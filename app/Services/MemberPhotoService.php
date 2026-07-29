<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Exceptions\MemberPhotoException;
use App\Models\MemberPhoto;
use App\Models\MemberPhotoAccess;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida da foto efêmera do membro: cap, prazo, leitura e destruição.
 * Ver docs/SECURITY_ISSUES.md § 1.1–§ 1.11.
 *
 * ── As regras vivem AQUI, não nos controllers ───────────────────────────────
 * Mesma razão do item 9 do CLAUDE.md (o guard do Ghost Mode no
 * ProfileVisitService, não nos dois controllers que o chamam): a segunda porta
 * de entrada que aparecesse — API Sanctum, painel de admin, um comando —
 * nasceria sem o cap ou sem a checagem de prazo. Os endpoints de upload/revoke/
 * serve (PR 3) só delegam.
 *
 * Por isso os métodos que leem ou concedem recebem o ATOR e conferem o vínculo
 * aqui — não é redundância com a Policy do controller, é a consequência de
 * "os endpoints só delegam": um `readForPerformer($access)` que confiasse no
 * chamador entregaria, com route-model binding e um id incrementado, a foto que
 * o membro mandou para OUTRA performer, e ainda carimbaria `viewed_at` na conta
 * errada. A Policy no controller é a segunda camada, não a primeira.
 *
 * ── Modo Discreto e Ghost Mode NÃO bloqueiam (§ 1.11) ───────────────────────
 * E isto é regra escrita, não omissão. `ProfileVisitService::record()` barra o
 * membro discreto porque visita é LISTAGEM — a performer o veria sem que ele
 * tivesse feito nada. Mandar a própria foto é o oposto: auto-listagem
 * voluntária, com aviso na tela de envio (§ 1.1). Bloquear aqui tiraria do
 * membro discreto uma escolha que é dele. Não "consertar" em nenhum dos dois
 * sentidos sem passar pelo PO.
 */
class MemberPhotoService
{
    /**
     * Folga antes de uma foto vencida-e-ainda-presente virar alarme.
     *
     * O GC roda de hora em hora, então encontrar arquivos vencidos é o trabalho
     * normal dele. O que NÃO é normal é encontrar arquivos que já estavam
     * vencidos na rodada anterior: significa que aquela rodada não os removeu.
     * 2h é uma janela de GC inteira de folga — número persistente diferente de
     * zero é o alarme do § 1.3, e hoje não há alerta de job em lugar nenhum.
     */
    public const STALE_AFTER_HOURS = 2;

    /** Teto do nome original guardado. Ver sanitizeFilename(). */
    private const FILENAME_MAX = 120;

    public function __construct(private MemberPhotoStore $store) {}

    /**
     * Recebe a foto do membro. Aplica o cap de ativas ANTES de existir linha.
     *
     * ── O cap é autorização, e por isso é no submit (§ 1.6) ─────────────────
     * Verificar na limpeza deixaria a janela aberta justo onde o abuso acontece:
     * o GC roda de hora em hora, então um laço de upload gravaria centenas de
     * arquivos e só seria podado no tick seguinte — o disco já teria enchido.
     *
     * ── E por que sob lock ──────────────────────────────────────────────────
     * `count()` seguido de `insert()` não é um cap: dois submits concorrentes
     * leem 4 e gravam os dois, e 5 vira 6. O lock é na linha do TITULAR, e não
     * nas fotos: as linhas que fariam o cap estourar são justamente as que ainda
     * não existem, e não há o que travar numa linha inexistente. Travando o
     * dono, os dois submits do mesmo membro serializam. Mesmo `lockForUpdate` do
     * recovery code de 2FA.
     *
     * ── Ordem: bytes primeiro, linha depois ─────────────────────────────────
     * O arquivo é gravado FORA da transação — higienizar e cifrar são o passo
     * caro, e segurá-lo com um lock de linha aberto serializaria uploads
     * simultâneos do mesmo membro por segundos. A contrapartida é a compensação
     * no catch: se o cap (ou qualquer falha) derrubar a transação, os bytes
     * recém-gravados vão embora junto. O inverso — linha primeiro, arquivo
     * depois — deixaria uma foto que a tela lista e o serving não consegue
     * abrir, que é pior: o membro acharia que compartilhou algo que não existe.
     *
     * @param  int  $ttlHours  um de MemberPhoto::TTL_HOURS
     *
     * @throws MemberPhotoException cap estourado ou TTL fora do menu
     * @throws ImageProcessingException imagem recusada
     */
    public function create(User $member, UploadedFile $file, int $ttlHours): MemberPhoto
    {
        if (! in_array($ttlHours, MemberPhoto::TTL_HOURS, true)) {
            throw MemberPhotoException::invalidTtl($ttlHours);
        }

        $path = $this->store->store($file, $member->id);

        try {
            return DB::transaction(function () use ($member, $file, $path, $ttlHours) {
                User::query()->whereKey($member->getKey())->lockForUpdate()->first();

                // MESMO critério do serving (escopo do model): contar linha já
                // vencida travaria o membro em 5 fotos mortas esperando o GC, e
                // o cap viraria bug de produto em vez de defesa.
                $active = MemberPhoto::query()->activeForUser($member->id)->count();

                if ($active >= MemberPhoto::ACTIVE_LIMIT) {
                    throw MemberPhotoException::activeLimitReached();
                }

                $photo = new MemberPhoto([
                    'expires_at' => now()->addHours($ttlHours),
                    'size_bytes' => $this->store->size($path),
                ]);

                // Fora do fillable: dono vem do request autenticado, caminho vem
                // do Store. Nenhum dos dois aceita payload.
                $photo->user_id = $member->id;
                $photo->path_encrypted = $path;
                $photo->original_filename = $this->sanitizeFilename($file->getClientOriginalName());
                $photo->save();

                return $photo;
            });
        } catch (Throwable $e) {
            // Compensação best-effort: se ela TAMBÉM falhar, o que sobe é a
            // exceção original — trocar uma pela outra esconderia do chamador o
            // motivo real (cap estourado, TTL inválido) e mostraria ao membro um
            // erro de disco. O que fica no chão é um arquivo órfão sem linha,
            // que é o follow-up 6 da revisão (varredura de órfãos).
            try {
                $this->store->delete($path);
            } catch (Throwable $cleanupFailed) {
                // Nem caminho nem titular: o log não é lugar de mapa de quem
                // mandou foto (princípio 4). Só o motivo.
                Log::warning('member-photos: bytes órfãos após falha no submit', [
                    'exception' => $cleanupFailed->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Dá (ou renova) o acesso de uma performer a uma foto.
     *
     * O prazo do acesso é o MENOR entre o pedido e o da foto: acesso não
     * sobrevive ao conteúdo. Sem o clamp, um grant de 7 dias sobre uma foto de
     * 24h deixaria uma linha "ativa" apontando para bytes que o GC já levou — e
     * a tela da performer mostraria "Expira nesta semana" para uma foto morta.
     *
     * Renova em vez de empilhar (índice único do par): o agregado que o membro
     * vê é "com quantas PERFORMERS você compartilhou" (§ 1.1), não quantos
     * grants ele emitiu.
     *
     * Quem concede é o TITULAR, e é conferido aqui: sem isso um `$photo` vindo
     * de route-model binding deixaria qualquer membro autenticado compartilhar
     * a foto de outro. Foto morta também não se concede — o grant carimbaria um
     * acesso já vencido pelo clamp, e a tela da performer mostraria "Expirada"
     * para algo que ela nunca poderia ter visto.
     *
     * @throws MemberPhotoException não é o dono, ou a foto já morreu
     */
    public function grantTo(User $owner, MemberPhoto $photo, PerformerProfile $profile, ?int $ttlHours = null): MemberPhotoAccess
    {
        $this->assertOwns($owner, $photo);

        if ($photo->trashed() || $photo->isExpired()) {
            throw MemberPhotoException::expired();
        }

        $requested = $ttlHours === null
            ? $photo->expires_at
            : now()->addHours($ttlHours);

        $expiresAt = $requested->greaterThan($photo->expires_at)
            ? $photo->expires_at
            : $requested;

        // Escrita explícita, e não `updateOrCreate`: as duas FKs e o `expires_at`
        // saíram do `$fillable` (o prazo é DERIVADO do clamp acima — aceitá-lo
        // por payload devolveria ao cliente justamente o que o clamp tira), e o
        // `fill()` interno do updateOrCreate DESCARTARIA o prazo em silêncio: o
        // acesso deixaria de renovar sem ninguém perceber.
        //
        // O catch repõe o que se perdeu junto com o updateOrCreate. Ele cai em
        // `createOrFirst()`, que trata a violação do índice único re-consultando;
        // um select-then-insert cru devolve 500 quando dois grants do mesmo par
        // chegam juntos — duplo clique em "compartilhar" ou retry do cliente.
        // O índice segura o dado; o que falta tratar é a resposta.
        try {
            return $this->writeAccess($photo, $profile, $expiresAt);
        } catch (UniqueConstraintViolationException) {
            return $this->writeAccess($photo, $profile, $expiresAt);
        }
    }

    /** Insere ou renova a linha do par. Ver o catch em grantTo(). */
    private function writeAccess(MemberPhoto $photo, PerformerProfile $profile, $expiresAt): MemberPhotoAccess
    {
        $access = MemberPhotoAccess::query()
            ->where('member_photo_id', $photo->id)
            ->where('performer_profile_id', $profile->id)
            ->first() ?? new MemberPhotoAccess;

        $access->member_photo_id = $photo->id;
        $access->performer_profile_id = $profile->id;
        $access->granted_at = now();
        $access->expires_at = $expiresAt;
        $access->save();

        return $access;
    }

    /**
     * Bytes da foto para o TITULAR — conferido que é ele mesmo.
     *
     * @throws MemberPhotoException não é o dono, vencida ou já destruída
     */
    public function readForMember(User $member, MemberPhoto $photo): string
    {
        $this->assertOwns($member, $photo);

        if ($photo->trashed() || $photo->isExpired()) {
            throw MemberPhotoException::expired();
        }

        return $this->store->retrieve($photo->path_encrypted);
    }

    /**
     * Bytes da foto para a PERFORMER que recebeu o acesso.
     *
     * Confere os DOIS prazos, e é aqui que o TTL vale (§ 1.3): se o único
     * mecanismo que cortasse o acesso fosse o job apagando o arquivo, um job
     * parado não custaria disco — custaria privacidade. O job é só garbage
     * collection; quem nega é esta função.
     *
     * `$access->photo` já vem sem as soft-deletadas (escopo global do
     * SoftDeletes), então foto destruída cai no mesmo `null` de foto inexistente.
     *
     * O ator entra como `User` e o perfil é resolvido AQUI, nunca recebido: um
     * parâmetro `PerformerProfile` deixaria o controller escolher contra quem
     * comparar, que é o mesmo furo com um passo a mais.
     *
     * @throws MemberPhotoException acesso de outra performer, ou vencido de
     *                              qualquer um dos dois lados
     */
    public function readForPerformer(User $performer, MemberPhotoAccess $access): string
    {
        $profileId = $performer->performerProfile?->getKey();

        if ($profileId === null || $access->performer_profile_id !== $profileId) {
            throw MemberPhotoException::forbidden();
        }

        $photo = $access->photo;

        if ($photo === null || $photo->isExpired() || $access->isExpired()) {
            throw MemberPhotoException::expired();
        }

        $bytes = $this->store->retrieve($photo->path_encrypted);

        // Depois de entregar: marcar antes e falhar na leitura registraria uma
        // visualização que não aconteceu.
        $access->markViewed();

        return $bytes;
    }

    /**
     * Revoke pelo TITULAR — a porta que o endpoint de revoke (PR 3) chama.
     *
     * `destroy()` é primitivo de sistema (GC, encerramento de conta) e não tem
     * ator; esta é a versão com dono, pela mesma razão dos outros três verbos:
     * com route-model binding em `DELETE /fotos/{photo}` e um controller que só
     * delega, um `destroy($photo)` exposto deixaria qualquer membro autenticado
     * apagar a foto de outro — bytes, acessos e linha.
     *
     * @throws MemberPhotoException não é o dono
     */
    public function destroyForMember(User $member, MemberPhoto $photo): void
    {
        $this->assertOwns($member, $photo);

        $this->destroy($photo);
    }

    /**
     * Destrói UMA foto: bytes do disco, acessos e linha, tudo de vez.
     *
     * É o primitivo que o GC e o encerramento de conta compartilham — duas
     * cópias divergiriam, e a que esquecesse os acessos deixaria de pé o mapa de
     * quem mostrou o rosto para quem (§ 1.8). **Sem ator**: quem vem do request
     * entra por destroyForMember().
     *
     * A ordem é bytes → banco. Falha ao apagar o arquivo ABORTA e deixa a linha
     * de pé, para a rodada seguinte tentar de novo: soft-deletar aqui esconderia
     * do GC um arquivo que continua no disco, e o alarme de vencidas-e-presentes
     * nunca mais o veria.
     *
     * Isso só é verdade porque `MemberPhotoStore::delete()` LANÇA quando o disco
     * recusa — o disco roda com `throw => false` e, sem a checagem de retorno de
     * lá, esta linha seguiria em frente com o arquivo intacto. As duas coisas
     * são uma decisão só; não desfaça uma sem a outra.
     *
     * ── A linha sai em HARD delete, e só depois de confirmar os bytes ───────
     * O `exists()` entre as duas etapas não é paranoia com o retorno do
     * `delete()`: é o que garante que a linha só é largada quando não há mais
     * nada para varrer. Enquanto o arquivo estiver lá, a linha é o ÚNICO ponteiro
     * para ele — todo o GC parte da tabela.
     *
     * E é `forceDelete` porque a linha morta não é neutra: guarda `user_id`,
     * `size_bytes` e `created_at`, ou seja "o membro X mandou 43 fotos, nestes
     * horários". Guardá-la indefinidamente reporia, em metadado, a retenção que
     * a foto efêmera existe para não ter — a mesma razão dos 7 dias de
     * `profile_visits`. O `deleted_at` continua existindo como estado
     * INTERMEDIÁRIO (uma rodada que apagou a linha mas não os bytes, por outro
     * caminho), e é isso que a varredura `withTrashed()` do GC recolhe.
     *
     * O DELETE dos acessos é explícito porque o `cascadeOnDelete` da FK só
     * dispararia no hard delete — e o caminho que importa proteger é o soft
     * (item 11 do CLAUDE.md, verbatim). Explícito nos dois, para não depender de
     * qual deles rodou.
     */
    public function destroy(MemberPhoto $photo): void
    {
        $this->store->delete($photo->path_encrypted);

        if ($this->store->exists($photo->path_encrypted)) {
            throw new RuntimeException('Foto efêmera continua no disco após o delete.');
        }

        DB::transaction(function () use ($photo) {
            $photo->accesses()->delete();
            $photo->forceDelete();
        });
    }

    /**
     * Garbage collection das fotos vencidas.
     *
     * Não é o mecanismo de expiração — é o que recolhe depois dele (§ 1.3).
     *
     * `stale` é o alarme: fotos que já estavam vencidas na rodada anterior e
     * continuam com arquivo no disco. Em operação normal é zero, porque cada
     * rodada limpa o que venceu na sua hora. Persistentemente diferente de zero
     * significa que o GC não está conseguindo apagar — e como a expiração vale
     * na leitura, isso é custo de disco, não de privacidade.
     *
     * ── A varredura é `withTrashed()`, e isso não é detalhe ─────────────────
     * A linha soft-deletada some do escopo padrão. Se algum caminho apagou a
     * linha mas NÃO o arquivo, uma varredura sem `withTrashed` nunca mais
     * olharia para aquele arquivo — o rosto do membro ficaria no disco
     * indefinidamente e o alarme marcaria zero, que é o pior dos dois mundos.
     * `destroy()` não produz esse estado (aborta antes, e termina em hard
     * delete); a varredura ampla é a rede para quando ele chegar por outro
     * caminho.
     *
     * Linha já soft-deletada E sem arquivo é o trabalho concluído: sai da
     * contagem e não é re-destruída. Como `destroy()` apaga a linha de vez, esse
     * `continue` é caso de borda e não o caso comum — o ramo trashed não cresce
     * com o histórico, senão cada rodada horária decifraria `path_encrypted` de
     * toda foto que já existiu só para pular.
     *
     * @return array{expired:int,deleted:int,stale:int,failed:int}
     */
    public function purgeExpired(): array
    {
        $counts = ['expired' => 0, 'deleted' => 0, 'stale' => 0, 'failed' => 0];
        $staleBefore = now()->subHours(self::STALE_AFTER_HOURS);

        MemberPhoto::withTrashed()
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($photos) use (&$counts, $staleBefore) {
                foreach ($photos as $photo) {
                    $onDisk = $this->store->exists($photo->path_encrypted);

                    if ($photo->trashed() && ! $onDisk) {
                        continue;
                    }

                    $counts['expired']++;

                    if ($photo->expires_at->lessThan($staleBefore) && $onDisk) {
                        $counts['stale']++;
                    }

                    try {
                        $this->destroy($photo);
                        $counts['deleted']++;
                    } catch (Throwable $e) {
                        $counts['failed']++;

                        // Id da LINHA, nunca o caminho nem o titular: o log não
                        // é lugar de mapa de quem mandou foto (princípio 4).
                        Log::warning('member-photos:purge falhou em uma foto', [
                            'member_photo_id' => $photo->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $counts;
    }

    /**
     * O ator é o dono da foto?
     *
     * Uma dona só para o critério, pela mesma razão do `activeForUser()`: o
     * upload, o grant, a leitura e o revoke precisam concordar sobre o que é
     * "sua foto", e duas versões divergiriam no sentido permissivo.
     *
     * @throws MemberPhotoException
     */
    private function assertOwns(User $member, MemberPhoto $photo): void
    {
        if ($photo->user_id !== $member->getKey()) {
            throw MemberPhotoException::forbidden();
        }
    }

    /**
     * O nome original é decorativo — só volta para o próprio titular.
     *
     * `basename()` porque o cliente escolhe a string inteira e ela nunca deve
     * poder virar caminho (o caminho no disco é gerado pelo Store, mas um nome
     * com barra vazaria como diretório em qualquer tela que o exiba). O corte de
     * comprimento não é cosmético: a coluna é cifrada, e o ciphertext cresce com
     * a entrada.
     */
    private function sanitizeFilename(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        // \p{C}: controles e ZWSP — o mesmo achado da revisão do filtro de chat,
        // onde caractere invisível atravessava a normalização.
        $clean = preg_replace('/\p{C}+/u', '', basename($name)) ?? '';
        $clean = trim($clean);

        return $clean === '' ? null : Str::limit($clean, self::FILENAME_MAX, '');
    }
}
