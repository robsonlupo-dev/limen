<?php

namespace App\Services;

use App\Exceptions\StoryException;
use App\Models\Circle;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\StoryView;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem pode ver qual Story, resolvido AGORA. Ver docs/SECURITY_ISSUES.md § 2.3.
 *
 * ── Por que é um service, e por que a checagem é por request ─────────────────
 * O § 2.3 é sobre o padrão que NÃO se pode copiar: `routes/api.php` serve mídia
 * com `middleware('signed')` e sem auth de sessão. Para avatar público está
 * certo; para Story Nível 3 é fatal — a assinatura não amarra viewer nenhum,
 * então a URL do membro Black é um **bearer token** que ele cola no WhatsApp, e
 * qualquer pessoa deslogada baixa o arquivo até a expiração. A monetização do
 * Modelo C evapora.
 *
 * Agrava que o tier já é levemente stale: `ExpireSubscriptions` roda de hora em
 * hora, então uma assinatura cancelada ainda vale por até 60 minutos. URL
 * assinada de longa duração EMPILHARIA as duas janelas. Daí a decisão: `auth` +
 * follow e tier resolvidos a cada request. **Se um dia entrar URL assinada, ela é
 * adicional e curtíssima — nunca substituta desta checagem.**
 *
 * ── Uma dona só para a regra ────────────────────────────────────────────────
 * Quatro consumidores hoje: o serving dos bytes, o feed, o pontinho de "não
 * visto" do catálogo (`profileIdsWithUnseenStories()`) e a faixa de stories do
 * perfil (`profileStripFor()`). Os dois primeiros pelo predicado `canView()`; os
 * dois últimos pela MESMA `LEVEL_CAPABILITIES`, um em SQL e o outro por item.
 *
 * É a disciplina do item 9 do CLAUDE.md e a mesma razão pela qual a tela de
 * seguidores e o envio de Interesse compartilham o `FollowerVisibilityService`: se
 * duas superfícies discordarem, o par (tela mostra / serving nega) — ou pior,
 * (tela esconde / serving entrega) — vira oráculo e vira furo de paywall. Por isso
 * o feed FILTRA em PHP pelo mesmo predicado em vez de reescrever a regra em SQL, e
 * por isso o filtro que PRECISA ser SQL lê a tabela em vez de repetir a regra:
 * duas implementações do mesmo critério divergem, e divergem no sentido
 * permissivo.
 */
class StoryVisibilityService
{
    /**
     * Tier mínimo do Nível 3 (`exclusive`).
     *
     * Coincide hoje com `PrivacyPerkService::MIN_TIER`, e a coincidência é
     * intencional mas não é a mesma regra: lá é "quem compra invisibilidade",
     * aqui é "quem alcança o conteúdo exclusivo". Constante própria para que
     * mexer numa não mexa na outra sem alguém decidir.
     *
     * Comparado por RANK via `Circle::tierAtLeast()`, que é fail-closed nas duas
     * pontas — tier novo acima de Black herda o acesso sem editar este arquivo.
     */
    public const EXCLUSIVE_MIN_TIER = 'black';

    /** Teto de stories que o feed monta numa passada. Ver `feedFor()`. */
    public const FEED_LIMIT = 200;

    /** O membro segue ESTA performer. Capacidade por par, não da conta. */
    public const CAP_FOLLOW = 'follow';

    /** Assinatura viva de qualquer tier. */
    public const CAP_CIRCLE = 'circle';

    /** Assinatura viva de Black ou acima. */
    public const CAP_EXCLUSIVE_TIER = 'exclusive_tier';

    /**
     * A regra de visibilidade, em forma de tabela: nível → capacidades que o
     * abrem (QUALQUER uma basta).
     *
     * ── Por que tabela, e não três `if` ────────────────────────────────────
     * A regra passou a ter dois consumidores de FORMA diferente: o predicado
     * linha-a-linha (`levelAllows()`, usado pelo serving e pelo feed) e um FILTRO
     * EM SQL (`profileIdsWithUnseenStories()`, que decide o pontinho do catálogo
     * numa query só, sem N+1). Escrever a mesma regra duas vezes — uma em PHP e
     * uma em `whereIn` — é exatamente a divergência que o docblock da classe
     * recusa, e ela apareceria no sentido permissivo: o SQL é o que a tela usa
     * para decidir se ANUNCIA conteúdo, e um `in` largo demais anunciaria o
     * exclusivo de quem não paga.
     *
     * Com a tabela, os dois derivam da MESMA fonte: o predicado intersecta as
     * capacidades do membro com a linha do nível; o SQL pergunta à tabela quais
     * níveis aquelas capacidades abrem. Nível novo entra aqui e chega aos dois de
     * uma vez — e, se alguém esquecer de mapeá-lo, os dois falham FECHADO
     * (interseção vazia), que é o lado certo de errar.
     *
     * @var array<string, array<int, string>>
     */
    public const LEVEL_CAPABILITIES = [
        // Nível 1 — seguidores, MAIS a exceção do PO: Black/FC vê story público
        // de performer que ainda não segue. É o que faz o Nível 1 funcionar como
        // vitrine para quem já paga. Ver story NÃO cria Follow (§ 2.10).
        'public' => [self::CAP_FOLLOW, self::CAP_EXCLUSIVE_TIER],
        // Nível 2 — qualquer Círculo ativo, sem exigir follow.
        'subscribers' => [self::CAP_CIRCLE],
        // Nível 3 — Black/FC, e só.
        PerformerStory::VISIBILITY_EXCLUSIVE => [self::CAP_EXCLUSIVE_TIER],
    ];

    /**
     * Este membro pode ver este story, neste instante?
     *
     * A ordem das checagens não é indiferente: prazo e estado da performer vêm
     * ANTES do nível, porque são as respostas que valem para todo mundo — testar
     * o tier primeiro faria um story morto responder 403 (a resposta do upsell)
     * em vez de 404, e o par de status devolveria o nível de um story que já não
     * existe.
     *
     * ── A performer também precisa estar de pé ──────────────────────────────
     * Perfil encerrado (soft delete) ou conta fora de `active` — suspensa,
     * pendente, BANIDA — não tem story servível. Sem esta checagem, o conteúdo de
     * uma conta suspensa por moderação continuaria a ser entregue pelas 24h de
     * TTL, que é exatamente a janela em que a moderação precisa que ele pare.
     * `withTrashed()` no usuário porque encerramento de conta é soft delete
     * (item 11 do CLAUDE.md): sem isso a relação devolveria `null` e o `?->`
     * cairia no mesmo `false` por acidente, não por decisão.
     */
    public function canView(PerformerStory $story, ?User $member): bool
    {
        return $this->denialFor($story, $member) === null;
    }

    /**
     * A MESMA decisão de `canView()`, dizendo POR QUE negou: `null` (pode ver),
     * `StoryException::EXPIRED` ou `StoryException::FORBIDDEN`.
     *
     * Existe porque as duas respostas HTTP são diferentes — 404 para vencido/fora
     * do ar, 403 para nível insuficiente (o upsell do § 2.3) — e a escolha entre
     * elas é da REGRA, não do controller. Se o controller decidisse, o motivo
     * viraria heurística ("não vejo o story, então deve ter expirado") e o par de
     * status passaria a vazar o que o 404 existe para esconder.
     *
     * "Fora do ar" (performer suspensa/banida/encerrada) responde EXPIRED de
     * propósito: 403 diria ao membro que a conta dela existe e está em outro
     * estado, e estado da conta dela não é informação dele — mesma disciplina do
     * `no_active_chat` da foto efêmera.
     */
    public function denialFor(PerformerStory $story, ?User $member): ?string
    {
        if ($member === null || $member->role !== 'consumer') {
            return StoryException::FORBIDDEN;
        }

        if ($story->trashed() || $story->isExpired()) {
            return StoryException::EXPIRED;
        }

        $profile = $story->performerProfile;

        if ($profile === null || ! $this->performerIsReachable($profile)) {
            return StoryException::EXPIRED;
        }

        return $this->levelAllows($story, $profile, $member) ? null : StoryException::FORBIDDEN;
    }

    /**
     * O NÍVEL do story admite este membro? Só o nível — o resto é `denialFor()`.
     *
     * Fail-closed por construção: nível fora da `LEVEL_CAPABILITIES` (enum
     * ampliado sem passar por lá) tem lista de requisitos vazia, e interseção
     * vazia é `false`. Num gate de paywall, "não sei comparar" é "não" — a mesma
     * escolha de `Circle::tierAtLeast()`.
     */
    private function levelAllows(PerformerStory $story, PerformerProfile $profile, User $member): bool
    {
        return $this->levelIsOpenTo(
            $story->visibility_level,
            $this->capabilitiesFor($member, $profile),
        );
    }

    /** Uma linha da tabela contra um conjunto de capacidades. */
    private function levelIsOpenTo(?string $level, array $capabilities): bool
    {
        return array_intersect(self::LEVEL_CAPABILITIES[$level] ?? [], $capabilities) !== [];
    }

    /**
     * O que este membro tem, em capacidades — resolvido AGORA (§ 2.3).
     *
     * `$profile` null resolve só as capacidades de CONTA (Círculo e tier), sem a
     * de par (`follow`). É o que o filtro em lote usa: ele precisa das capacidades
     * uma vez e resolve o follow no próprio SQL, por performer.
     *
     * @return array<int, string>
     */
    private function capabilitiesFor(User $member, ?PerformerProfile $profile = null): array
    {
        $capabilities = [];

        if ($this->hasActiveCircle($member)) {
            $capabilities[] = self::CAP_CIRCLE;
        }

        if ($this->hasTierAtLeast($member, self::EXCLUSIVE_MIN_TIER)) {
            $capabilities[] = self::CAP_EXCLUSIVE_TIER;
        }

        if ($profile !== null && $this->follows($profile, $member)) {
            $capabilities[] = self::CAP_FOLLOW;
        }

        return $capabilities;
    }

    /**
     * Quais níveis estas capacidades abrem. É a leitura da tabela na direção
     * inversa — o que o filtro em SQL precisa.
     *
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    private function levelsOpenTo(array $capabilities): array
    {
        $levels = [];

        foreach (array_keys(self::LEVEL_CAPABILITIES) as $level) {
            if ($this->levelIsOpenTo($level, $capabilities)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /**
     * Feed do membro: stories que ele pode ver, agrupados por performer.
     *
     * ── O candidato vem do banco, a DECISÃO vem do `canView()` ──────────────
     * A query só reduz o universo (stories vivos de quem ele segue + os públicos
     * de todas, quando ele é Black/FC); quem autoriza cada linha é o predicado.
     * Escrever a regra de novo em SQL seria a segunda implementação que o
     * docblock da classe recusa.
     *
     * O `FEED_LIMIT` é teto de segurança, não paginação: a janela de 24h já
     * limita o conjunto, mas um Black num dia de pico não pode transformar um GET
     * de feed numa varredura ilimitada. Se a plataforma crescer até encostar
     * nele, o conserto é paginar — não subir o número em silêncio.
     *
     * ── `seen` é dado do PRÓPRIO membro ────────────────────────────────────
     * O indicador de não-visto sai de `story_views` filtrada pelo id DELE, então
     * não expõe ninguém: é a mesma informação que o app já lhe deu ao abrir.
     *
     * > **Consequência conhecida do Ghost Mode:** a view do membro com o perk não
     * > é gravada (§ 2.7), então para ele o story fica **permanentemente "não
     * > visto"**. É o preço do perk — a alternativa seria gravar a linha, que é
     * > justamente o que ele compra para não existir. Registrado para não ser
     * > lido como bug: não "conserte" com uma coluna de leitura paralela.
     *
     * @return array<int, array<string, mixed>> uma entrada por performer
     */
    public function feedFor(User $member): array
    {
        $candidates = $this->feedCandidates($member);

        $visible = $candidates->filter(fn (PerformerStory $story) => $this->canView($story, $member));

        $seenIds = $this->seenStoryIds($member, $visible->pluck('id')->all());

        return $visible
            ->groupBy('performer_profile_id')
            ->map(function (Collection $stories) use ($seenIds) {
                $profile = $stories->first()->performerProfile;

                $items = $stories
                    // Mais antigo primeiro DENTRO da performer: é ordem de
                    // publicação, que é como um story se lê.
                    ->sortBy('id')
                    ->map(fn (PerformerStory $story) => [
                        'id' => $story->id,
                        'visibility_level' => $story->visibility_level,
                        'seen' => in_array($story->id, $seenIds, true),
                    ])
                    ->values()
                    ->all();

                return [
                    'performer' => [
                        'stage_name' => $profile->stage_name,
                        'slug' => $profile->slug,
                    ],
                    'stories' => $items,
                    'has_unseen' => collect($items)->contains(fn (array $item) => ! $item['seen']),
                    // Só para ordenar as performers entre si; o id do story já
                    // sai na lista, então não acrescenta informação.
                    'latest_story_id' => $stories->max('id'),
                ];
            })
            // Quem tem novidade primeiro; depois, quem publicou mais recentemente.
            ->sortByDesc(fn (array $group) => [$group['has_unseen'] ? 1 : 0, $group['latest_story_id']])
            ->values()
            ->all();
    }

    /**
     * Dos perfis desta página do catálogo, quais têm story que ESTE membro pode
     * ver e ainda não viu. É o que acende o pontinho dourado no avatar.
     *
     * ── Uma query para a página inteira ────────────────────────────────────
     * Chamado uma vez com os ids da página (o catálogo pagina em 20/24), nunca
     * por card: um `exists()` por performer seria N+1 na tela mais visitada do
     * produto. O `whereNotExists` sobre `story_views` e o `whereExists` sobre
     * `follows` são correlacionados, então o banco resolve tudo numa passada.
     *
     * ── A regra continua tendo uma dona só ─────────────────────────────────
     * Os níveis do `whereIn` vêm da `LEVEL_CAPABILITIES` (ver o docblock dela),
     * não de um `if` escrito aqui. `follow` é capacidade de PAR, então entra como
     * subconsulta correlacionada em vez de virar uma lista de ids: o membro pode
     * seguir 800 performers, e um `whereIn` de 800 ids por página é o tipo de
     * query que degrada em silêncio.
     *
     * ── O que este booleano é, e o que ele não é ───────────────────────────
     * É dado do MEMBRO ("tem novidade para você"), não da performer. A performer
     * não recebe nada daqui: quem viu o quê continua fechado — ela só tem a FAIXA
     * de membros únicos (§ 2.1/§ 2.2), e o pontinho não conta nem informa nada a
     * ela. Também não é autorização: quem entrega bytes é
     * `PerformerStoryService::readForMember()`, que reavalia tudo no request.
     *
     * > **Consequência conhecida do Ghost Mode:** a view de quem tem o perk não é
     * > gravada (§ 2.7), então para ele o pontinho **nunca apaga**. É o preço do
     * > perk — a alternativa seria gravar a linha, que é justamente o que ele
     * > compra para não existir. Igual ao `seen` do feed; não "conserte" com uma
     * > coluna de leitura paralela.
     *
     * Visitante deslogado (e performer/admin) recebe lista vazia sem tocar o
     * banco: não há "não visto" para quem não tem conta.
     *
     * @param  array<int, int>  $profileIds
     * @return array<int, int>
     */
    public function profileIdsWithUnseenStories(array $profileIds, ?User $member): array
    {
        if ($profileIds === [] || $member === null || $member->role !== 'consumer') {
            return [];
        }

        // Capacidades de CONTA, resolvidas uma vez para a página toda.
        $accountCapabilities = $this->capabilitiesFor($member);

        $withoutFollow = $this->levelsOpenTo($accountCapabilities);
        $withFollow = $this->levelsOpenTo([...$accountCapabilities, self::CAP_FOLLOW]);

        // O que SÓ o follow abre. `array_diff` e não uma lista escrita à mão:
        // se a tabela mudar, os dois lados mudam juntos.
        $followOnly = array_values(array_diff($withFollow, $withoutFollow));

        if ($withoutFollow === [] && $followOnly === []) {
            return [];
        }

        return PerformerStory::query()
            ->active()
            ->whereIn('performer_profile_id', $profileIds)
            ->where(function ($query) use ($withoutFollow, $followOnly, $member) {
                if ($withoutFollow !== []) {
                    $query->orWhereIn('visibility_level', $withoutFollow);
                }

                if ($followOnly !== []) {
                    $query->orWhere(fn ($q) => $q
                        ->whereIn('visibility_level', $followOnly)
                        ->whereExists(fn ($sub) => $sub
                            ->selectRaw('1')
                            ->from('follows')
                            ->whereColumn('follows.performer_profile_id', 'performer_stories.performer_profile_id')
                            ->where('follows.user_id', $member->getKey())
                        )
                    );
                }
            })
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('story_views')
                ->whereColumn('story_views.performer_story_id', 'performer_stories.id')
                ->where('story_views.user_id', $member->getKey())
            )
            ->distinct()
            ->pluck('performer_profile_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Os stories vivos desta performer para a tela de perfil dela.
     *
     * Cada item diz se o espectador ALCANÇA o story (`locked`) e se já o viu
     * (`seen`). O nível sai junto porque a tela desenha o cadeado por nível
     * (nenhum / pequeno / dourado) — é a informação que o § 2.3 aceita expor: o
     * membro saber que existe conteúdo que ele ainda não pode ver É o upsell do
     * Modelo C, e é a mesma coisa que o 403 do serving já diz.
     *
     * ── `image_url` é null quando `locked`, e isso é o paywall ─────────────
     * **Blur em CSS não é paywall**: uma miniatura de verdade entregue "borrada"
     * pelo cliente está inteira no DevTools em dois cliques. Story fechado não
     * ganha URL nenhuma — a tela desenha um placeholder, não uma versão
     * degradada do conteúdo. É a mesma disciplina do serving, onde a
     * autorização decide ANTES de os bytes saírem do disco.
     *
     * ── Visitante deslogado ────────────────────────────────────────────────
     * Vê que existem stories, todos fechados (nenhuma capacidade), e a tela leva
     * ao cadastro — mesmo caminho de toda ação da página pública. Não há
     * `story_views` para consultar, então nada é "visto".
     *
     * A performer que a tela mostra vem sempre do escopo `publicCatalog()` (as
     * duas rotas de perfil resolvem por ele), que já exige conta ativa e perfil
     * verificado — não há performer fora do ar com página de perfil de pé. E, de
     * todo modo, quem decide a entrega dos bytes é `readForMember()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function profileStripFor(PerformerProfile $profile, ?User $member): array
    {
        $stories = PerformerStory::query()
            ->active()
            ->where('performer_profile_id', $profile->getKey())
            // Mais antigo primeiro: é ordem de publicação, que é como um story se
            // lê. Mesma ordem do feed.
            ->orderBy('id')
            ->get();

        if ($stories->isEmpty()) {
            return [];
        }

        $isMember = $member !== null && $member->role === 'consumer';

        $capabilities = $isMember ? $this->capabilitiesFor($member, $profile) : [];
        $seenIds = $isMember ? $this->seenStoryIds($member, $stories->pluck('id')->all()) : [];

        return $stories->map(function (PerformerStory $story) use ($capabilities, $seenIds) {
            $locked = ! $this->levelIsOpenTo($story->visibility_level, $capabilities);

            return [
                'id' => $story->id,
                'visibility_level' => $story->visibility_level,
                'locked' => $locked,
                'seen' => in_array($story->id, $seenIds, true),
                // Ver o docblock: fechado não recebe URL.
                'image_url' => $locked ? null : route('stories.image', $story->id),
            ];
        })->all();
    }

    /**
     * O universo que o feed considera, antes do predicado.
     *
     * `whereIn` sobre os perfis seguidos, mais — para quem tem o tier — os
     * públicos de todas as performers. A união é feita numa query só (`orWhere`
     * agrupado) para não trazer duas coleções e deduplicar em PHP: o story de uma
     * performer que ele segue E que é público apareceria nas duas.
     *
     * @return Collection<int, PerformerStory>
     */
    private function feedCandidates(User $member): Collection
    {
        $followedProfileIds = Follow::query()
            ->where('user_id', $member->getKey())
            ->pluck('performer_profile_id')
            ->all();

        $seesAllPublic = $this->hasTierAtLeast($member, self::EXCLUSIVE_MIN_TIER);

        return PerformerStory::query()
            ->active()
            ->with('performerProfile.user')
            ->where(function ($query) use ($followedProfileIds, $seesAllPublic) {
                $query->whereIn('performer_profile_id', $followedProfileIds);

                if ($seesAllPublic) {
                    $query->orWhere('visibility_level', 'public');
                }
            })
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /**
     * Quais destes stories o membro já abriu.
     *
     * Uma query para o lote inteiro: um `exists()` por story faria o feed
     * disparar N SELECTs, e o feed é a tela mais carregada da feature.
     *
     * @param  array<int, int>  $storyIds
     * @return array<int, int>
     */
    private function seenStoryIds(User $member, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        return StoryView::query()
            ->where('user_id', $member->getKey())
            ->whereIn('performer_story_id', $storyIds)
            ->pluck('performer_story_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** O membro segue esta performer? Resolvido no request, nunca em cache. */
    private function follows(PerformerProfile $profile, User $member): bool
    {
        return Follow::query()
            ->where('performer_profile_id', $profile->getKey())
            ->where('user_id', $member->getKey())
            ->exists();
    }

    /** Assinatura viva de qualquer tier (Nível 2). */
    private function hasActiveCircle(User $member): bool
    {
        return $member->activeCircle() !== null;
    }

    /**
     * Assinatura viva de `$minSlug` ou acima.
     *
     * A comparação é de `Circle::tierAtLeast()`, que é a dona dela e é
     * fail-closed: slug desconhecido de qualquer um dos lados nega. A forma
     * ingênua (`tierRank() >= array_search(...)`) falha ABERTO — ver o docblock
     * daquele método.
     */
    private function hasTierAtLeast(User $member, string $minSlug): bool
    {
        $circle = $member->activeCircle();

        return $circle instanceof Circle && $circle->tierAtLeast($minSlug);
    }

    /**
     * A performer está de pé para ter conteúdo servido?
     *
     * Mesma checagem (e mesma razão) de `MemberPhotoService::performerIsReachable()`.
     */
    private function performerIsReachable(PerformerProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }

        $user = $profile->user()->withTrashed()->first();

        return $user !== null && ! $user->trashed() && $user->status === 'active';
    }
}
