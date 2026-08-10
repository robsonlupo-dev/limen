<?php

namespace App\Services;

use App\Models\PerformerProfile;
use App\Models\PerformerProfilePreviousSlug;
use App\Support\BrazilianCities;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class PerformerCatalogService
{
    /**
     * The four public "worlds" surfaced on the catalog — the single source of
     * truth is PerformerProfile::WORLDS. The whereIn stays as a defensive guard
     * so a card never renders a world the UI has no label/icon for.
     */
    public const PUBLIC_WORLDS = PerformerProfile::WORLDS;

    /**
     * Regras de validação dos filtros do catálogo (Sprint 9).
     *
     * Fonte ÚNICA para as três superfícies que listam performer: catálogo
     * autenticado (web), catálogo público (web) e `GET /api/v1/performers`.
     * Antes de existirem filtros ricos a divergência era barata — o público só
     * tinha mundo. Com dez facetas, três cópias divergiriam na primeira, e a
     * assimetria entre o que uma tela oferece e o que o servidor aceita é
     * exatamente o tipo de buraco que o CLAUDE.md manda fechar com uma dona só.
     *
     * Os conjuntos válidos vêm das constantes do model (TAG_GROUPS, LANGUAGES,
     * DRINKS, SMOKES, TIERS, faixa de altura) — nunca de uma lista repetida
     * aqui, senão acrescentar uma tag exigiria mexer em dois lugares.
     *
     * @return array<string, mixed>
     */
    public static function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:rating_avg,followers_count,newest'],
            'level' => ['nullable', 'in:iniciante,estrela,premium,vip'],
            'is_live' => ['nullable', 'boolean'],
            // "Disponíveis agora" (Sprint 11): filtra por quem sinalizou
            // disponibilidade dentro da janela de 4h. Ver scopeAvailableForChat.
            'available' => ['nullable', 'boolean'],

            // Teto no array: sem ele, `tags[]` com 200 entradas monta um
            // whereIn gigante por request não autenticado. O teto é o mesmo
            // MAX_TAGS que uma performer pode ter — pedir mais do que isso não
            // pode casar com ninguém, então não há filtro legítimo perdido.
            'tags' => ['nullable', 'array', 'max:'.PerformerProfile::MAX_TAGS],
            'tags.*' => ['string', Rule::in(PerformerProfile::allTags())],

            'languages' => ['nullable', 'array', 'max:'.count(PerformerProfile::LANGUAGES)],
            'languages.*' => ['string', Rule::in(PerformerProfile::LANGUAGES)],

            'drinks' => ['nullable', Rule::in(PerformerProfile::DRINKS)],
            'smokes' => ['nullable', Rule::in(PerformerProfile::SMOKES)],
            'tier' => ['nullable', Rule::in(PerformerProfile::TIERS)],
            'has_photo' => ['nullable', 'boolean'],

            // Localização: filtra por UF sempre; por CIDADE só quando a performer
            // consentiu (opt-in `findable_by_city`, item 4 da fila). O filtro de
            // cidade NUNCA expõe a cidade — ela só afeta a busca de quem optou.
            // A cidade é o NOME do município (o autocomplete manda o valor do
            // IBGE); casa pela `city_normalized` no scopeInCity. Sem validar
            // contra a lista aqui de propósito: nome fora do IBGE simplesmente
            // não casa (não há filtro legítimo perdido), e validar custaria
            // carregar a lista no caminho quente da listagem pública.
            'state' => ['nullable', Rule::in(PerformerProfile::STATES)],
            'city' => ['nullable', 'string', 'max:120'],

            'height_min' => [
                'nullable', 'integer',
                'min:'.PerformerProfile::HEIGHT_MIN_CM,
                'max:'.PerformerProfile::HEIGHT_MAX_CM,
            ],
            'height_max' => [
                'nullable', 'integer',
                'min:'.PerformerProfile::HEIGHT_MIN_CM,
                'max:'.PerformerProfile::HEIGHT_MAX_CM,
            ],
        ];
    }

    /**
     * Aplica as facetas do Sprint 9 à query do catálogo. Chamada pelas três
     * superfícies — ver filterRules().
     *
     * Toda faceta é OPCIONAL e ausente significa "não filtra". Nenhuma delas
     * mexe no recorte de segurança: `publicCatalog()` (ativa + verificada +
     * com slug) já foi aplicado pelo chamador e não é afrouxável daqui.
     *
     * @param  array<string, mixed>  $filters  já validados por filterRules()
     * @param  Builder<PerformerProfile>  $query
     * @return Builder<PerformerProfile>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['is_live'])) {
            $query->where('is_live', true);
        }

        // "Disponíveis agora" (Sprint 11). Delega ao scope que é a dona da
        // janela de 4h — o número não se repete aqui (ver isAvailableForChat).
        if (! empty($filters['available'])) {
            $query->availableForChat();
        }

        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        if (! empty($filters['tier']) && in_array($filters['tier'], PerformerProfile::TIERS, true)) {
            $query->where('tier', $filters['tier']);
        }

        // "Com fotos". `is_verified` NÃO tem filtro irmão de propósito: o
        // publicCatalog() já exige `is_verified = true`, então uma caixa
        // "Selfie verificada" nunca mudaria o resultado — e, pior, sugeriria
        // que existe performer não verificada na listagem. Ver o README do PR.
        if (! empty($filters['has_photo'])) {
            $query->whereNotNull('avatar_path');
        }

        // Tags em AND: a performer precisa ter TODAS as escolhidas.
        //
        // `whereHas(..., '=', count)` e não só o whereIn — o whereIn sozinho dá
        // OR ("tem qualquer uma"), que com 3 tags devolve quase o catálogo
        // inteiro e faz o filtro parecer quebrado. A contagem é exata porque a
        // junção tem índice único (performer_profile_id, tag_slug): não há
        // duplicata que infle o total.
        $tags = array_values(array_filter((array) ($filters['tags'] ?? []), 'is_string'));

        if ($tags !== []) {
            $query->whereHas(
                'tags',
                fn (Builder $q) => $q->whereIn('tag_slug', $tags),
                '=',
                count(array_unique($tags)),
            );
        }

        // Idiomas em OR — e a assimetria com as tags é deliberada. Tag é
        // atributo ("quero alguém fitness E viajante"); idioma é CANAL: quem
        // marca português e inglês fala os dois e quer quem consiga conversar
        // em QUALQUER um deles. Em AND, marcar o segundo idioma diminuiria o
        // resultado — o oposto do que a pessoa quis dizer ao marcá-lo.
        $languages = array_values(array_filter((array) ($filters['languages'] ?? []), 'is_string'));

        if ($languages !== []) {
            $query->where(function (Builder $q) use ($languages) {
                foreach ($languages as $language) {
                    $q->orWhereJsonContains('languages', $language);
                }
            });
        }

        // Localização (Sprint 13: múltiplas). Casa se QUALQUER localização da
        // performer bate a UF (OR), via scopeInState — a dona da regra, com o
        // fallback para o cache `state` da coluna. Quem não preencheu simplesmente
        // não casa: o campo é opt-in, e filtrar por SP é pedir quem se declarou em
        // SP. Sem filtro, todo mundo continua aparecendo.
        //
        // Cidade (item 4 da fila): mais estreita que a UF e CONSENTIDA. Quando a
        // performer optou por ser encontrável por cidade (`findable_by_city`), o
        // scopeInCity casa pela `city_normalized`. A cidade tem precedência sobre
        // a UF (ela IMPLICA a UF — o autocomplete manda a UF do município junto,
        // e ela desambigua homônimos), então não empilhamos scopeInState por cima.
        $city = is_string($filters['city'] ?? null) ? BrazilianCities::normalize($filters['city']) : '';
        $state = (! empty($filters['state']) && in_array($filters['state'], PerformerProfile::STATES, true))
            ? $filters['state']
            : null;

        if ($city !== '') {
            $query->inCity($city, $state);
        } elseif ($state !== null) {
            $query->inState($state);
        }

        if (! empty($filters['drinks']) && in_array($filters['drinks'], PerformerProfile::DRINKS, true)) {
            $query->where('drinks', $filters['drinks']);
        }

        if (! empty($filters['smokes']) && in_array($filters['smokes'], PerformerProfile::SMOKES, true)) {
            $query->where('smokes', $filters['smokes']);
        }

        // Altura: só filtra quando o membro ESTREITOU a faixa.
        //
        // `height_cm` é nullable e a maioria dos perfis não o preencheu; um
        // whereBetween aplicado à faixa cheia (140–190) descartaria todas essas
        // performers sem que ninguém tivesse pedido nada. Como o slider manda
        // os dois extremos a cada request, aplicar sempre faria o catálogo
        // encolher no instante em que a gaveta de filtros abrisse — sem
        // nenhuma faceta marcada, e sem explicação na tela.
        $min = $filters['height_min'] ?? null;
        $max = $filters['height_max'] ?? null;
        $narrowed = ($min !== null && (int) $min > PerformerProfile::HEIGHT_MIN_CM)
            || ($max !== null && (int) $max < PerformerProfile::HEIGHT_MAX_CM);

        if ($narrowed) {
            $query->whereNotNull('height_cm')->whereBetween('height_cm', [
                (int) ($min ?? PerformerProfile::HEIGHT_MIN_CM),
                (int) ($max ?? PerformerProfile::HEIGHT_MAX_CM),
            ]);
        }

        // Busca textual. O agrupamento é obrigatório: sem a closure, o orWhere
        // escaparia do AND das outras facetas e a busca devolveria performer de
        // outro mundo/tier. `like` com o termo escapado pelo bind do Eloquent.
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('stage_name', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Public (no-auth) catalog listing. Unlike search(), it does not force a
     * single world: with no filter it shows every public world; an optional
     * world narrows to one. Only active + verified performers, never pending.
     *
     * As facetas do Sprint 9 valem aqui exatamente como no catálogo
     * autenticado, via applyFilters() — a tela pública oferece as mesmas
     * caixas, e um filtro que só funcionasse depois do login seria uma
     * diferença invisível na URL compartilhada.
     *
     * @param  array<string, mixed>  $filters  já validados por filterRules()
     */
    public function publicSearch(?string $world = null, array $filters = []): LengthAwarePaginator
    {
        $query = PerformerProfile::query()
            ->publicCatalog()
            ->whereIn('category', self::PUBLIC_WORLDS);

        if ($world !== null && in_array($world, self::PUBLIC_WORLDS, true)) {
            // Multi-worlds: matches the `worlds` list, with a category fallback
            // for rows not yet migrated. See PerformerProfile::scopeInWorld.
            $query->inWorld($world);
        }

        $this->applyFilters($query, $filters);

        // A ordenação do público é própria (seguidores primeiro, é vitrine) e
        // não vem do `sort` do membro — mas o `sort` continua valendo quando
        // vem explícito, para o link compartilhado abrir igual.
        match ($filters['sort'] ?? null) {
            'newest' => $query->orderByDesc('created_at'),
            'rating_avg' => $query->orderByDesc('rating_avg'),
            default => $query->orderByDesc('followers_count')->orderByDesc('rating_avg'),
        };

        return $query->paginate(24)->withQueryString();
    }

    /**
     * Resolve a single public profile by slug, scoped so pending/unverified
     * performers 404 even when the slug is known.
     */
    public function findPublicBySlug(string $slug): PerformerProfile
    {
        return PerformerProfile::publicCatalog()
            ->whereIn('category', self::PUBLIC_WORLDS)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $query = PerformerProfile::query()->publicCatalog();

        // Guard alinhado ao publicSearch(): só filtra por um mundo conhecido.
        // O único chamador (CatalogController) já valida com Rule::in, mas o
        // guard aqui impede que o próximo chamador reabra a assimetria.
        if (! empty($filters['category']) && in_array($filters['category'], self::PUBLIC_WORLDS, true)) {
            $query->inWorld($filters['category']);
        }

        // Todas as facetas (inclusive is_live, level e a busca textual, que já
        // viviam aqui) passaram para applyFilters — ver filterRules().
        $this->applyFilters($query, $filters);

        match ($filters['sort'] ?? 'rating_avg') {
            'followers_count' => $query->orderByDesc('followers_count'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('rating_avg'),
        };

        return $query->paginate(20)->withQueryString();
    }

    public function findBySlug(string $slug): PerformerProfile
    {
        return PerformerProfile::publicCatalog()->where('slug', $slug)->firstOrFail();
    }

    /**
     * Slug ATUAL de uma performer que largou `$oldSlug` num rename, ou null.
     * Alimenta o 301-redirect da show do catálogo AUTENTICADO — mesmo recorte
     * de visibilidade da findBySlug, para não redirecionar para uma página que
     * então daria 404 (performer que ficou pending/suspensa some do redirect).
     */
    public function currentSlugForPrevious(string $oldSlug): ?string
    {
        return $this->resolvePreviousSlug(PerformerProfile::publicCatalog(), $oldSlug);
    }

    /**
     * Idem, para a show PÚBLICA (/performers/{slug}) — recorte da
     * findPublicBySlug (só os mundos públicos, ativa + verificada).
     */
    public function currentPublicSlugForPrevious(string $oldSlug): ?string
    {
        return $this->resolvePreviousSlug(
            PerformerProfile::publicCatalog()->whereIn('category', self::PUBLIC_WORLDS),
            $oldSlug,
        );
    }

    private function resolvePreviousSlug(Builder $query, string $oldSlug): ?string
    {
        // Resolve o id ANTES de aplicar o recorte de visibilidade: um JOIN
        // dentro do scope publicCatalog() colidiria com o `whereNotNull('slug')`
        // dele (coluna `slug` ambígua). Duas queries, sem ambiguidade.
        $profileId = PerformerProfilePreviousSlug::where('slug', $oldSlug)
            ->value('performer_profile_id');

        if ($profileId === null) {
            return null;
        }

        // null se o perfil não passa mais no recorte (pending/suspensa/mundo
        // fora): o link antigo 404, não redireciona para uma página escondida.
        return $query->where('performer_profiles.id', $profileId)->value('slug');
    }
}
