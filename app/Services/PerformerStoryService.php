<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Models\StoryView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida do Story da performer: publicação, view, contador e destruição.
 * Ver docs/SECURITY_ISSUES.md § 2.1 a § 2.10.
 *
 * ── As regras vivem AQUI, não nos controllers nem no Vue ────────────────────
 * Item 9 do CLAUDE.md e § 2.7, textualmente: o guard do Ghost Mode mora no
 * `ProfileVisitService::record()` e não nos dois controllers que o chamam,
 * porque "a terceira rota que aparecesse nasceria vazando". `story_views` É a
 * terceira rota. O mesmo vale para o contador (§ 2.2/decisão nº 3): a segunda
 * superfície que o pedisse — API, painel, export de métricas — nasceria
 * vazando, e filtrar no front seria pior ainda, porque o número trafegaria nas
 * props do Inertia e bastaria abrir o DevTools.
 *
 * Os endpoints (PR 2) e a checagem de visibilidade por tier (PR 3) só delegam.
 */
class PerformerStoryService
{
    /**
     * Folga antes de um story vencido-e-ainda-presente virar alarme.
     *
     * O GC roda de hora em hora, então encontrar arquivos vencidos é o trabalho
     * normal dele. O que NÃO é normal é encontrar arquivos que já estavam
     * vencidos na rodada anterior: significa que aquela rodada não os removeu.
     * Mesma constante e mesmo raciocínio do `MemberPhotoService`.
     */
    public const STALE_AFTER_HOURS = 2;

    /**
     * Janela em que o GC ainda reexamina linha soft-deletada à procura de bytes
     * órfãos. Ver o docblock de `purgeExpired()`.
     */
    public const RESCUE_WINDOW_HOURS = 2;

    /**
     * Status de denúncia que CONGELAM o GC daquele story (§ 2.4, parte 2).
     *
     * O achado mais sério da feature é que o auto-delete de 24h é destruição de
     * prova embutida no produto: o `DeletionService` preserva `reports` intactos
     * de propósito — "apagá-la porque o denunciado pediu exclusão daria ao
     * infrator um botão para destruir a prova contra si" — e Stories daria esse
     * botão a toda performer, automático, a cada 24 horas. Denúncia na hora 23
     * seria revisada contra nada.
     *
     * `pending` e `reviewed` são os estados EM ABERTO: o admin ainda não concluiu.
     * `resolved` e `dismissed` são conclusão, e aí o GC volta a agir.
     *
     * O outro lado disso ainda não existe e é o PR 5: `PerformerStory` não está
     * em `Report::REPORTABLE_TYPES`, então **hoje nenhum story chega a ser
     * denunciado**. A quarentena é escrita antes do gatilho de propósito — a
     * ordem inversa (denúncia primeiro, quarentena depois) é exatamente a janela
     * em que a evidência some.
     */
    public const OPEN_REPORT_STATUSES = ['pending', 'reviewed'];

    public function __construct(private PerformerStoryStore $store) {}

    /**
     * Publica um story. Prazo fixo de 24h, EXIF removido, bytes antes da linha.
     *
     * ── Ordem: bytes primeiro, linha depois ─────────────────────────────────
     * Mesma escolha do `MemberPhotoService::create()`, pelo mesmo motivo: o
     * inverso deixaria um story que o feed lista e o serving não consegue abrir.
     * A contrapartida é a compensação no catch — se a escrita da linha falhar, os
     * bytes recém-gravados vão embora junto, senão um laço de upload recusado
     * encheria o disco sem deixar linha (e todo o GC parte da tabela).
     *
     * ── O nível é validado aqui, não só no Form Request ─────────────────────
     * Erro de CHAMADOR, não do usuário: o Form Request do PR 2 recusa antes. A
     * checagem existe para que um segundo ponto de entrada não nasça aceitando
     * `visibility_level` arbitrário — o enum do banco recusaria em modo estrito,
     * mas com um 500 em vez de uma recusa legível, e em modo não-estrito
     * gravaria string vazia: um story sem nível é um story sem paywall.
     *
     * O que este método NÃO decide: se esta performer pode publicar (KYC, aceite
     * de documentos, conta ativa) é gate de rota, e quem pode VER cada nível é o
     * PR 3.
     *
     * @throws InvalidArgumentException nível fora dos três
     * @throws ImageProcessingException imagem recusada ou indecodificável
     */
    public function publish(PerformerProfile $profile, UploadedFile $file, string $visibility): PerformerStory
    {
        if (! in_array($visibility, PerformerStory::VISIBILITY_LEVELS, true)) {
            throw new InvalidArgumentException("Nível de visibilidade desconhecido: {$visibility}");
        }

        $path = $this->store->store($file, $profile->getKey());

        try {
            $story = new PerformerStory(['visibility_level' => $visibility]);

            // Fora do fillable: a dona vem do request autenticado, o caminho vem
            // do Store e o prazo é fixo do produto. Nenhum dos três aceita payload.
            $story->performer_profile_id = $profile->getKey();
            $story->media_path = $path;
            $story->expires_at = now()->addHours(PerformerStory::TTL_HOURS);
            $story->save();

            return $story;
        } catch (Throwable $e) {
            // Compensação best-effort: se ela TAMBÉM falhar, o que sobe é a
            // exceção original — trocar uma pela outra esconderia do chamador o
            // motivo real e mostraria à performer um erro de disco.
            try {
                $this->store->delete($path);
            } catch (Throwable $cleanupFailed) {
                Log::warning('stories: bytes órfãos após falha na publicação', [
                    'exception' => $cleanupFailed->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Registra que este membro viu este story — se for para registrar.
     *
     * ── O guard do Ghost Mode e do Modo Discreto vive AQUI (§ 2.7) ───────────
     * Não no controller: são o mesmo perk e a mesma regra do painel de visitantes
     * (item 9 do CLAUDE.md), e `story_views` é a terceira superfície. Vale também
     * a regra da ausência de linha: **não gravamos a view marcada como oculta** —
     * não existe coluna `hidden`. Guardar a view oculta deixaria o dado a um JOIN
     * de distância, e um bug de query viraria o vazamento exato que o perk vende.
     *
     * Silenciosamente não faz nada quando: não é membro (a própria performer,
     * outra performer, admin), o membro tem Ghost Mode, está em Modo Discreto, o
     * story morreu, ou ele já tinha visto. O retorno é só para TESTE — nenhum
     * chamador decide nada com ele, e a resposta do endpoint NÃO deve mudar
     * conforme a view foi ou não gravada: se diferisse, o Ghost Mode seria
     * detectável de fora. Mesmo contrato de `ProfileVisitService::record()`.
     *
     * ── Não gravar view NÃO é não poder ver ─────────────────────────────────
     * Este método é contabilidade de audiência, não autorização. Quem decide se o
     * membro vê os bytes é a checagem de visibilidade por tier (PR 3) mais o
     * prazo; o membro com Ghost Mode vê o story normalmente — ele só não entra na
     * conta. Por isso o story vencido também apenas não é contado aqui, em vez de
     * lançar: a recusa da LEITURA é do serving, e duplicá-la aqui daria dois
     * lugares para divergir.
     *
     * ── Ver story NUNCA cria Follow (§ 2.10, decisão do PO) ─────────────────
     * Nem aqui nem em chamador nenhum. Se abrir um story auto-seguisse, o membro
     * que só queria espiar entraria numa lista que a performer pode ver e que o
     * habilita a RECEBER Interesse Controlado: a intenção ("ver um story") ≠ a
     * consequência. Seguir continua sendo clique explícito, e não há
     * `follows.source` para registrar de onde veio (§ 2.9) — seria atributo novo
     * do membro visível à performer, e tentaria alguém a filtrar o piso por
     * origem, bifurcando um critério que tem uma dona só.
     */
    public function viewStory(PerformerStory $story, User $viewer): ?StoryView
    {
        // Só membro entra na conta. Cobre a própria performer olhando o próprio
        // story (ela infla a faixa dela mesma), outras performers e admins.
        if ($viewer->role !== 'consumer') {
            return null;
        }

        // Modo Discreto é "conta para o piso mas nunca é listado", e a faixa é a
        // forma agregada da listagem. Sem esta linha o perk se fura por uma
        // superfície nova — e fura JUSTO no caso que a regra 3 do CLAUDE.md
        // protege: quem ativou Discreto como Black e depois lapsou o tier
        // continua discreto, mas deixa de ser elegível ao Ghost Mode, e voltaria
        // a ser contado aqui por causa do lapso de pagamento.
        if ($viewer->discrete_mode) {
            return null;
        }

        // O perk. A checagem vem ANTES de qualquer escrita — ver o docblock.
        if ($viewer->hasGhostMode()) {
            return null;
        }

        if ($story->trashed() || $story->isExpired()) {
            return null;
        }

        // Reabrir não reescreve nada: `viewed_at` é a PRIMEIRA vez. A coluna
        // viraria um log de quando o membro volta a olhar — comportamento dele,
        // que nenhuma tela pode mostrar.
        if ($this->alreadyViewed($story, $viewer)) {
            return null;
        }

        try {
            return $this->writeView($story, $viewer);
        } catch (UniqueConstraintViolationException) {
            // Duplo clique ou retry do cliente: o índice único do par segura o
            // dado, o que falta é não devolver 500. Já visto = nada a fazer.
            return null;
        }
    }

    /**
     * Contador de audiência do story — em FAIXA, ou nada.
     *
     * Devolve o rótulo (`Menos de 5` / `5+` / …) ou `null` no Nível 3. **Não
     * devolve número**, e a assinatura é a regra: um `int` daqui obrigaria a tela
     * a rotular, e aí o número exato trafegaria nas props do Inertia — o mesmo
     * erro que o `FanAlias` evitou ao tirar o `member_id` cru do payload. Regra 5
     * do CLAUDE.md: contagem sempre em faixa, **inclusive para a própria
     * performer**.
     *
     * ── `null` no Nível 3, e não zero (§ 2.2, decisão nº 3 — Opção B) ───────
     * Story exclusivo (Black/FC) não exibe contador de forma alguma. Qualquer
     * sinal de audiência ali prova que aqueles viewers são Black/FC: postar um
     * Nível 1 e um Nível 3 com minutos de diferença faz a DIFERENÇA entre os
     * conjuntos entregar o tier, e como o `FanAlias` é estável, "Fã #0042 viu meu
     * exclusivo" = "Fã #0042 é Black/FC" = alvo de alto valor carimbado
     * permanentemente. Sem o segundo operando, a subtração não existe.
     *
     * A contradição de produto vale ser dita: o tier que COMPRA invisibilidade
     * (`PrivacyPerkService::MIN_TIER = 'black'`) viraria o mais identificável da
     * plataforma. O Nível 3 venderia ao Black o oposto do que ele já comprou.
     *
     * **`null` e não zero é segurança, não estilo.** Zero é um valor no mesmo
     * domínio da faixa: uma tela que trate `0` como "ninguém viu" afirmaria algo
     * falso sobre a audiência — o mesmo erro que a copy do painel de visitantes
     * evita de propósito ("Nada a mostrar", deliberadamente ambígua). `null`
     * significa "esta pergunta não é respondida neste nível". A UI renderiza
     * ausência, não `0`. E a ausência não vaza nada: a performer escolheu o nível
     * ao postar, então ela já sabe por que não há contador ali — é função do
     * nível, que é dado dela, não do conjunto de quem viu.
     *
     * ── Nível 2 MANTÉM contador, e é coerente ──────────────────────────────
     * Nível 2 é "qualquer Círculo ativo", e o perk de invisibilidade começa em
     * Black: ser assinante de algum tier não é a categoria que comprou
     * invisibilidade. O que a faixa do Nível 2 revela é quantos seguidores
     * assinam alguma coisa — métrica de negócio legítima. A linha do corte cai
     * exatamente onde o perk começa.
     *
     * ── A tabela de faixas é a de seguidores, e não uma segunda ─────────────
     * `PerformerProfile::followersLabelFor()` é a dona (decisão nº 1), pela mesma
     * razão que a elegibilidade do piso tem uma só: duas tabelas divergem, e
     * divergiriam no sentido permissivo.
     */
    public function viewCount(PerformerStory $story): ?string
    {
        if ($story->visibility_level === PerformerStory::VISIBILITY_EXCLUSIVE) {
            return null;
        }

        return PerformerProfile::followersLabelFor($this->distinctViewerCount($story));
    }

    /**
     * Membros ÚNICOS que viram o story.
     *
     * `DISTINCT` explícito mesmo com o índice único do par garantindo uma linha
     * por membro: é a decisão nº 1 do PO escrita em código — **`DISTINCT
     * member_id` ANTES da faixa, nunca depois**. Faixar o total de ABERTURAS
     * devolveria comportamento do membro em vez de audiência, e um membro que
     * reabre 5 vezes empurraria sozinho a faixa de `Menos de 5` para `5+`.
     *
     * Privado: o número cru não sai deste service (ver `viewCount()`).
     */
    private function distinctViewerCount(PerformerStory $story): int
    {
        return StoryView::query()
            ->where('performer_story_id', $story->getKey())
            ->distinct()
            ->count('user_id');
    }

    /**
     * Destrói UM story: bytes do disco e views, e só então larga a linha.
     *
     * É o primitivo que o GC e (no PR 2) o delete manual da performer
     * compartilham — duas cópias divergiriam, e a que esquecesse as views
     * deixaria de pé o mapa de quem viu o quê (§ 2.6).
     *
     * A ordem é bytes → banco. Falha ao apagar o arquivo ABORTA e deixa a linha
     * de pé, para a rodada seguinte tentar de novo: soft-deletar aqui esconderia
     * do GC um arquivo que continua no disco, e o alarme de vencidos-e-presentes
     * nunca mais o veria. Isso só é verdade porque `PerformerStoryStore::delete()`
     * LANÇA quando o disco recusa — as duas coisas são uma decisão só; não desfaça
     * uma sem a outra.
     *
     * ── A linha fica soft-deletada, e aqui a diferença com a foto é de conteúdo
     * `MemberPhotoService::destroy()` termina em `forceDelete` porque a linha
     * morta guardaria "o membro X mandou 43 fotos, nestes horários" — retenção de
     * PII de quem enviou. Esta linha é da própria performer sobre o próprio
     * conteúdo publicado, e é ela que dá lastro ao § 2.4: preservar evidência
     * SEM preservar conteúdo é justamente a pergunta que a feature levanta. O
     * hash da ingestão (§ 2.4, parte 1) pendura aqui no PR 5.
     *
     * ── As views saem em DELETE de verdade (§ 2.6) ──────────────────────────
     * Elas são o mapa de interesses membro→performer, estruturalmente idêntico a
     * `profile_visits`, e morrem com o story. O `cascadeOnDelete` da FK **não**
     * cobre: a linha do story sai por SOFT delete, e FK não dispara em UPDATE de
     * `deleted_at` (item 11 do CLAUDE.md, verbatim). Quem apaga é este código.
     */
    public function destroy(PerformerStory $story): void
    {
        $this->store->delete($story->media_path);

        if ($this->store->exists($story->media_path)) {
            throw new RuntimeException('Story continua no disco após o delete.');
        }

        DB::transaction(function () use ($story) {
            $story->views()->delete();
            $story->delete();
        });
    }

    /**
     * Garbage collection dos stories vencidos.
     *
     * **Não é o mecanismo de expiração** — é o que recolhe depois dele (§ 2.8).
     * Quem nega o story vencido é a leitura (escopo `active()` + `isExpired()`).
     *
     * ── Denúncia CONGELA o story (§ 2.4, parte 2) ───────────────────────────
     * Story com denúncia em aberto é PULADO por inteiro: bytes, linha e views
     * ficam de pé até um humano concluir. Sem isso, o auto-delete de 24h é o
     * botão que o `DeletionService` recusa dar ao denunciado — e a denúncia da
     * hora 23 chegaria para um arquivo que já não existe.
     *
     * O congelado sai da contagem de `stale` de propósito: ele é, por definição,
     * "vencido e ainda no disco", e contá-lo faria o único alarme de GC parado do
     * projeto (§ 1.3) marcar permanentemente diferente de zero — um alarme que
     * sempre apita é um alarme desligado. Vai para `quarantined`, que é o número
     * que o admin de verdade quer ver.
     *
     * ── A segunda varredura, e por que é limitada no tempo ──────────────────
     * `destroy()` só soft-deleta a linha DEPOIS de confirmar que os bytes saíram,
     * então "linha morta com arquivo de pé" é anomalia, não estado normal. Ainda
     * assim ela é alcançável por outro caminho (um `$story->delete()` cru num PR
     * futuro), e sem rede aqueles bytes ficariam no volume invisíveis a qualquer
     * rodada — a linha saiu do escopo padrão.
     *
     * A rede existe, mas é LIMITADA a `RESCUE_WINDOW_HOURS`, e a diferença com o
     * `withTrashed()` do `member-photos` é de custo: lá a linha termina em hard
     * delete, então a varredura ampla é barata; aqui a linha soft-deletada FICA,
     * e olhar o disco para toda linha que já venceu faria o custo por rodada
     * crescer com o histórico do produto para sempre. A janela cobre o caso real
     * (a anomalia acabou de acontecer) sem essa dívida. Bytes que escapem dela
     * custam DISCO, não privacidade: sem linha viva não há serving que os
     * alcance. Varredura de órfãos completa é follow-up — o mesmo já registrado
     * para a foto efêmera.
     *
     * @return array{expired:int,deleted:int,quarantined:int,stale:int,failed:int}
     */
    public function purgeExpired(): array
    {
        $counts = [
            'expired' => 0,
            'deleted' => 0,
            'quarantined' => $this->quarantinedCount(),
            'stale' => 0,
            'failed' => 0,
        ];

        $staleBefore = now()->subHours(self::STALE_AFTER_HOURS);

        $this->collectable()
            ->chunkById(200, function ($stories) use (&$counts, $staleBefore) {
                foreach ($stories as $story) {
                    $counts['expired']++;

                    if ($story->expires_at->lessThan($staleBefore) && $this->store->exists($story->media_path)) {
                        $counts['stale']++;
                    }

                    $this->collect($story, $counts);
                }
            });

        $this->rescueOrphanBytes($counts);

        return $counts;
    }

    /**
     * Os stories que o GC pode recolher: vencidos, ainda de pé, sem denúncia em
     * aberto.
     *
     * O filtro de denúncia é uma subconsulta e não um `pluck()` em PHP: a tabela
     * de denúncias cresce para sempre (nada apaga uma denúncia — é o lastro da
     * notificação), e trazer os ids para a memória a cada hora seria pagar o
     * histórico inteiro por rodada.
     */
    private function collectable(): Builder
    {
        return PerformerStory::query()
            ->where('expires_at', '<=', now())
            ->whereNotIn('id', $this->openReportTargets());
    }

    /** Quantos vencidos estão congelados por denúncia — ver `purgeExpired()`. */
    private function quarantinedCount(): int
    {
        return PerformerStory::query()
            ->where('expires_at', '<=', now())
            ->whereIn('id', $this->openReportTargets())
            ->count();
    }

    /**
     * Subconsulta dos stories com denúncia em aberto.
     *
     * `getMorphClass()` e não a string literal: não há morph map no projeto hoje
     * (o `reportable_type` guarda o FQCN), e escrever a classe à mão aqui seria a
     * cópia que deixa de casar no dia em que um morph map entrar — e deixaria de
     * casar em silêncio, liberando o GC sobre a evidência.
     */
    private function openReportTargets(): Builder
    {
        return Report::query()
            ->select('reportable_id')
            ->where('reportable_type', (new PerformerStory)->getMorphClass())
            ->whereIn('status', self::OPEN_REPORT_STATUSES);
    }

    /**
     * Recolhe um story e contabiliza. Falha de UM não derruba a rodada: o disco
     * pode recusar um caminho específico (permissão herdada errada num
     * subdiretório) e os outros continuam a ser recolhidos.
     *
     * @param  array{expired:int,deleted:int,quarantined:int,stale:int,failed:int}  $counts
     */
    private function collect(PerformerStory $story, array &$counts): void
    {
        try {
            $this->destroy($story);
            $counts['deleted']++;
        } catch (Throwable $e) {
            $counts['failed']++;

            // Id da LINHA, nunca o caminho: o log não é lugar do layout do disco.
            Log::warning('stories:purge falhou em um story', [
                'performer_story_id' => $story->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rede para o estado intermediário: linha soft-deletada com bytes de pé.
     * Ver o docblock de `purgeExpired()` para o porquê da janela.
     *
     * Conta como `stale` sempre: por construção este estado só existe quando
     * alguém apagou a linha sem apagar o arquivo — é anomalia, e é exatamente o
     * que o alarme deve mostrar.
     *
     * @param  array{expired:int,deleted:int,quarantined:int,stale:int,failed:int}  $counts
     */
    private function rescueOrphanBytes(array &$counts): void
    {
        PerformerStory::onlyTrashed()
            ->where('deleted_at', '>=', now()->subHours(self::RESCUE_WINDOW_HOURS))
            ->whereNotIn('id', $this->openReportTargets())
            ->chunkById(200, function ($stories) use (&$counts) {
                foreach ($stories as $story) {
                    if (! $this->store->exists($story->media_path)) {
                        continue;
                    }

                    $counts['expired']++;
                    $counts['stale']++;

                    $this->collect($story, $counts);
                }
            });
    }

    /** Este membro já contou nesta story? Ver o índice único do par. */
    private function alreadyViewed(PerformerStory $story, User $viewer): bool
    {
        return StoryView::query()
            ->where('performer_story_id', $story->getKey())
            ->where('user_id', $viewer->getKey())
            ->exists();
    }

    /**
     * Insere a linha da view. Escrita explícita porque o `$fillable` é vazio: as
     * duas FKs e o `viewed_at` são autoridade do servidor, e o mais perigoso é o
     * `user_id` — aceito por payload, gravaria view no nome de outro membro,
     * adulterando o `DISTINCT` que sustenta a faixa (e, com Ghost Mode,
     * plantando justamente a linha que o perk existe para não ter).
     */
    private function writeView(PerformerStory $story, User $viewer): StoryView
    {
        $view = new StoryView;
        $view->performer_story_id = $story->getKey();
        $view->user_id = $viewer->getKey();
        $view->viewed_at = now();
        $view->save();

        return $view;
    }
}
