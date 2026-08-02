<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PerformerProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The four official worlds (the `category` column). Single source of truth
     * for validation, seeding and the catalog. gls/swing retired 16/07/2026 —
     * see the retire_gls_swing_worlds migration.
     */
    public const WORLDS = ['mulheres', 'homens', 'casais', 'trans'];

    public const TIERS = ['verificada', 'select', 'maison'];

    /**
     * Janela de "Disponível para conversa" (Sprint 11), em horas. A
     * disponibilidade é DERIVADA do carimbo `available_for_chat_at`: a performer
     * está disponível quando ele é não-nulo e mais novo que esta janela. Fonte
     * única do número — o resource, o filtro e o dashboard perguntam aqui, nunca
     * repetem o `4`. Lida na LEITURA (isAvailableForChat), como o `is_live`: não
     * há job que apaga o estado, a expiração vale a cada request.
     */
    public const AVAILABILITY_WINDOW_HOURS = 4;

    /**
     * Tags da performer, agrupadas pela seção em que a tela as mostra. O grupo
     * é APRESENTAÇÃO — a validação e o filtro trabalham sobre o conjunto achatado
     * (allTags()), e a junção guarda só o slug. Uma tag pode mudar de grupo sem
     * migration nem reescrita de dado.
     *
     * Fonte única: o Form Request valida daqui, a tela de edição desenha daqui e
     * o catálogo filtra daqui. Uma segunda lista em qualquer um dos três abriria
     * a porta para a tela oferecer uma tag que o servidor recusa.
     */
    public const TAG_GROUPS = [
        'estilo_de_vida' => [
            'viajante', 'fitness', 'gourmet', 'praia', 'arte', 'musica',
            'moda', 'yoga', 'games', 'aventura', 'festa', 'luxo',
        ],
        'personalidade' => [
            'extrovertida', 'misteriosa', 'divertida', 'intelectual',
            'carinhosa', 'discreta', 'apaixonada', 'dominante', 'submissa',
        ],
        'oferece' => [
            'conversa', 'companhia', 'conteudo_exclusivo', 'live',
            'fantasia', 'roleplay', 'danca', 'striptease',
        ],
    ];

    /**
     * Teto de tags por performer. Vale como regra de produto (um perfil com 29
     * tags não diz nada) e como limite do que a junção aceita por perfil.
     */
    public const MAX_TAGS = 8;

    /**
     * Teto de fotos na galeria do perfil (Sprint 10, decisão do PO). Fonte única
     * do número, como MAX_TAGS: o PerformerPhotoService o aplica sob lock, o Form
     * Request pode citá-lo na mensagem e a tela desenha "3/6". Sem cifra por foto,
     * este cap é a única contenção de volume no disco `performer_photos`.
     */
    public const MAX_PHOTOS = 6;

    /**
     * Teto de localizações por performer (Sprint 13, decisão do PO). Fonte única
     * do número, como MAX_TAGS/MAX_PHOTOS: o PerformerLocationService o aplica sob
     * lock, o Form Request o cita na regra `max` e a tela desenha "3/3".
     */
    public const MAX_LOCATIONS = 3;

    public const LANGUAGES = [
        'portugues', 'ingles', 'espanhol', 'frances', 'italiano', 'alemao', 'japones',
    ];

    public const DRINKS = ['nao_bebe', 'bebe_socialmente', 'bebe_frequentemente'];

    public const SMOKES = ['nao_fuma', 'fuma_socialmente', 'fuma'];

    /** Faixa do slider de altura, em cm. Espelhada na tela e no Form Request. */
    public const HEIGHT_MIN_CM = 140;

    public const HEIGHT_MAX_CM = 190;

    /**
     * As 26 UFs + DF. Fonte única da validação (Form Request), do filtro do
     * catálogo e do dropdown da tela — que espelha esta lista em
     * `resources/js/lib/performerAttributes.js` só para ter o nome por extenso.
     *
     * É a UF que fica no banco e sai no resource, nunca o nome escrito: mesmo
     * padrão de `languages`/`drinks`/`tier`. E é a ÚNICA granularidade que sai
     * daqui — `city` existe na tabela, mas não é público (ver a migration e
     * PerformerPublicResource).
     */
    public const STATES = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA',
        'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    /**
     * O conjunto válido de tags, achatado. É o que a validação e o filtro usam —
     * o agrupamento só interessa a quem desenha a tela.
     *
     * @return array<int, string>
     */
    public static function allTags(): array
    {
        return array_merge(...array_values(self::TAG_GROUPS));
    }

    /**
     * tier, tier_granted_at e tier_granted_by ficam FORA do $fillable —
     * escrita somente via forceFill() em endpoint admin dedicado, mesmo
     * padrão do discrete_mode (anti mass assignment).
     */
    protected $fillable = [
        'stage_name', 'slug', 'bio', 'category', 'worlds', 'work_modes',
        'level', 'split_pct', 'rate_public', 'rate_private', 'rate_camera',
        'is_live', 'is_verified', 'avatar_path', 'cover_path',
        'languages', 'drinks', 'smokes', 'height_cm', 'looking_for',
        // Localização opt-in. `city` é fillable porque a performer a escreve na
        // própria tela — o que a mantém interna é não sair em resource nenhum,
        // não a ausência daqui. Ver PerformerPublicResource.
        'state', 'city',
    ];

    /**
     * `available_for_chat_at` é o carimbo bruto de "Disponível para conversa"
     * (Sprint 11). $hidden porque o público só pode ver o BOOLEANO derivado
     * (is_available no PerformerPublicResource), nunca o instante em que a
     * performer sinalizou — o timestamp exato é presença ao minuto, mesma
     * disciplina do `last_active_at` do User e do `visited_at` do painel de
     * visitantes. O código que precisa dele lê o atributo direto (o $hidden só
     * afeta a serialização), como isAvailableForChat() abaixo.
     */
    protected $hidden = [
        'available_for_chat_at',
        // Boost pago (Sprint 11): o carimbo do FIM do destaque. $hidden porque o
        // público só pode ver o BOOLEANO derivado (is_boosted no
        // PerformerPublicResource) — expor o `boosted_until` diria a hora exata
        // em que o destaque acaba (e, com a duração do config, quando começou).
        // Fora do $fillable também: a escrita só acontece no BoostService, por
        // forceFill, depois do débito no ledger — nunca de um payload de massa.
        'boosted_until',
    ];

    protected function casts(): array
    {
        return [
            'worlds' => 'array',
            'work_modes' => 'array',
            'languages' => 'array',
            'height_cm' => 'integer',
            'is_live' => 'boolean',
            'available_for_chat_at' => 'datetime',
            'boosted_until' => 'datetime',
            'is_verified' => 'boolean',
            'rating_avg' => 'decimal:2',
            'split_pct' => 'integer',
            'rate_public' => 'integer',
            'rate_private' => 'integer',
            'rate_camera' => 'integer',
            'rating_count' => 'integer',
            'followers_count' => 'integer',
            'tier_granted_at' => 'datetime',
        ];
    }

    /**
     * Contagem de seguidores em faixas, para exibição. O número exato de um
     * perfil pequeno é um canal lateral: quem observa o contador vê ele subir de
     * 3 para 4 no instante em que alguém segue e liga o evento à pessoa —
     * desfazendo o Piso de Anonimato sem nunca abrir a lista.
     *
     * A partir de 500 o número exato volta: nessa escala um incremento não
     * identifica ninguém, e a contagem é sinal social legítimo.
     */
    public function followersCountLabel(): string
    {
        return self::followersLabelFor((int) $this->followers_count);
    }

    /**
     * Mesma tabela de faixas para uma contagem qualquer. Existe porque nem toda
     * tela conta a mesma coisa: o contador denormalizado followers_count inclui
     * seguidores suspensos e contas apagadas, enquanto o Piso de Anonimato conta
     * só membros ativos. Cada tela rotula o número que ela de fato usa, senão
     * exibiria uma faixa que não corresponde ao que decidiu a visibilidade.
     */
    public static function followersLabelFor(int $count): string
    {
        return match (true) {
            $count < 5 => 'Menos de 5',
            $count < 10 => '5+',
            $count < 50 => '10+',
            $count < 100 => '50+',
            $count < 500 => '100+',
            default => number_format($count, 0, ',', '.'),
        };
    }

    /**
     * The worlds this performer belongs to. `worlds` is the source of truth;
     * a null column (row created before multi-worlds, or never migrated) falls
     * back to the single `category`, so every read path sees a non-empty list
     * without a data backfill having to run first.
     *
     * @return array<int, string>
     */
    public function activeWorlds(): array
    {
        return $this->worlds ?? [$this->category];
    }

    /**
     * O eager load do `user` existe pelo badge de e-mail verificado
     * (PerformerPublicResource): sem ele uma página de catálogo com 24 cards
     * dispara 24 SELECTs. Carrega a linha inteira de propósito — restringir as
     * colunas aqui deixaria `$profile->user->status`/`->email` null para todo
     * chamador do scope, e o scope tem sete deles.
     *
     * `tags` entra pelo mesmo motivo: o resource expõe os slugs em todo card, e
     * a junção sem eager load devolveria exatamente o N+1 que ela veio evitar.
     */
    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query
            ->with(['user', 'tags', 'locations'])
            // Contador da galeria (Sprint 10) para o selo "📷 N" no card. É
            // withCount e não with(): o card só precisa do NÚMERO, não das
            // linhas, e uma subquery agregada evita hidratar até 6 fotos por
            // perfil numa página de 24 cards. Vitrine pública legítima — ao
            // contrário do favorito, o número de fotos é da própria performer.
            ->withCount('photos')
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->whereNotNull('slug')
            // Boost pago (Sprint 11): boostados ANTES de todos, ordenados entre
            // si por quem tem o destaque mais recente (boosted_until maior).
            //
            // A expressão devolve o carimbo só para quem está boostado AGORA
            // (`boosted_until > now()`) e NULL para os demais — incluindo boost
            // VENCIDO (carimbo no passado), que assim não rouba posição de quem
            // nunca boostou. Como é a PRIMEIRA cláusula de ordenação, é a
            // primária: os serviços de catálogo acrescentam a ordenação normal
            // (seguidores/rating) DEPOIS, que passa a valer só como desempate
            // dentro de cada grupo. Sem boost ativo na base, todos caem em NULL
            // (empate) e a ordem existente decide tudo — por isso adicionar isto
            // aqui não mexe no catálogo de quem não usou o boost.
            //
            // NULLs vão por último em DESC (MySQL e SQLite concordam nisso), então
            // "boostado primeiro" sai de graça, sem um CASE/booleano extra.
            ->orderByRaw('CASE WHEN boosted_until > ? THEN boosted_until END DESC', [now()]);
    }

    /**
     * Narrow the catalog to a single world. A performer matches when the world
     * is in its `worlds` list (JSON_CONTAINS), OR — for rows not yet migrated —
     * when `worlds` is null and its `category` equals the world. Kept as its own
     * scope rather than folded into scopePublicCatalog(), which slug lookups
     * also use and must NOT filter by world.
     */
    public function scopeInWorld(Builder $query, string $world): Builder
    {
        return $query->where(function (Builder $q) use ($world) {
            $q->whereJsonContains('worlds', $world)
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('worlds')
                    ->where('category', $world));
        });
    }

    /**
     * Restringe o catálogo a quem tem ALGUMA localização na UF (Sprint 13).
     *
     * Casa se QUALQUER localização da performer bate (OR, via whereHas), OU — para
     * a linha que ainda não tem localização na tabela, só o cache `state` da
     * coluna — quando a coluna bate. É o mesmo fallback do `scopeInWorld`
     * (worlds JSON OU category legado): mantém filtráveis tanto as performers com
     * múltiplas localizações quanto as que só têm a coluna do Sprint 9A (linha de
     * teste criada direto, ou dado não migrado). A migração de dados popula a
     * tabela, então o ramo do fallback é rede, não o caminho comum.
     *
     * Uma dona só para "está nesta UF", pela mesma razão do scopeInWorld: o filtro
     * do catálogo autenticado, o público e a API passam por aqui — três cópias
     * divergiriam.
     *
     * @param  Builder<PerformerProfile>  $query
     * @return Builder<PerformerProfile>
     */
    public function scopeInState(Builder $query, string $state): Builder
    {
        return $query->where(function (Builder $q) use ($state) {
            $q->whereHas('locations', fn (Builder $inner) => $inner->where('state', $state))
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereDoesntHave('locations')
                    ->where('state', $state));
        });
    }

    /**
     * "Disponível para conversa" agora (Sprint 11), DERIVADO na leitura.
     *
     * Verdadeiro só quando a performer sinalizou (`available_for_chat_at`
     * não-nulo) E o sinal ainda está dentro da janela de AVAILABILITY_WINDOW_HOURS.
     * Sem job de expiração: exatamente como o `is_live` e o ChatAccess, o estado
     * "vence" na leitura. É o ÚNICO lugar que decide disponibilidade — o resource
     * (is_available), o scope do filtro e o dashboard passam por aqui.
     */
    public function isAvailableForChat(): bool
    {
        return $this->available_for_chat_at !== null
            && $this->available_for_chat_at->greaterThan(
                now()->subHours(self::AVAILABILITY_WINDOW_HOURS)
            );
    }

    /**
     * Tempo restante de disponibilidade em FAIXA, para o PRÓPRIO painel da
     * performer — nunca sai numa superfície pública. Faixa e não relógio pela
     * mesma disciplina da ActivitySlot/ExpirySlot: mesmo sendo o dado dela na
     * tela dela, a copy não promete um minuto exato que o produto não expõe em
     * lugar nenhum. Null quando não está disponível (a tela não desenha nada).
     */
    public function availabilityRemainingLabel(): ?string
    {
        if (! $this->isAvailableForChat()) {
            return null;
        }

        $endsAt = $this->available_for_chat_at->copy()->addHours(self::AVAILABILITY_WINDOW_HOURS);
        $hoursLeft = now()->diffInHours($endsAt, false);

        return match (true) {
            $hoursLeft >= 3 => 'por quase 4 horas',
            $hoursLeft >= 2 => 'por mais de 2 horas',
            $hoursLeft >= 1 => 'por mais de 1 hora',
            default => 'por menos de 1 hora',
        };
    }

    /**
     * Restringe o catálogo a quem está disponível para conversa agora (Sprint
     * 11) — o filtro "Disponíveis agora". Espelha isAvailableForChat() em SQL:
     * carimbo dentro da janela. Quem nunca sinalizou (`null`) ou sinalizou há
     * mais de AVAILABILITY_WINDOW_HOURS simplesmente não casa — como o filtro de
     * estado, a faceta é opt-in e ausência não é "perdido".
     *
     * @param  Builder<PerformerProfile>  $query
     * @return Builder<PerformerProfile>
     */
    public function scopeAvailableForChat(Builder $query): Builder
    {
        return $query->where(
            'available_for_chat_at',
            '>',
            now()->subHours(self::AVAILABILITY_WINDOW_HOURS)
        );
    }

    /**
     * O perfil está em destaque AGORA (Sprint 11), DERIVADO na leitura.
     *
     * Verdadeiro só quando `boosted_until` foi setado E ainda está no futuro.
     * Sem job de expiração — como `is_live`, `isAvailableForChat` e o ChatAccess,
     * o estado "vence" na leitura. É o ÚNICO lugar que decide destaque: o resource
     * (is_boosted), a ordenação do catálogo e o BoostService passam por aqui.
     */
    public function isBoosted(): bool
    {
        return $this->boosted_until !== null && $this->boosted_until->isFuture();
    }

    /**
     * Tempo restante de destaque em FAIXA, para o PRÓPRIO painel da performer —
     * nunca sai em superfície pública. Faixa e não relógio pela mesma disciplina
     * da availabilityRemainingLabel/ActivitySlot: mesmo sendo o dado dela na tela
     * dela, a copy não promete um minuto exato que o produto não expõe. Null
     * quando não está boostado (a tela não desenha nada).
     *
     * As faixas se adaptam à duração do config: quem tem 6h vê "por quase 6
     * horas" no começo; encolhe conforme o relógio anda.
     */
    public function boostRemainingLabel(): ?string
    {
        if (! $this->isBoosted()) {
            return null;
        }

        $hoursLeft = (int) ceil(now()->diffInMinutes($this->boosted_until, false) / 60);
        $hoursLeft = max(1, $hoursLeft);

        return $hoursLeft === 1
            ? 'por menos de 1 hora'
            : "por cerca de {$hoursLeft} horas";
    }

    /**
     * Restringe a quem está boostado agora (Sprint 11) — usado pelo BoostService
     * para contar os slots ocupados. Espelha isBoosted() em SQL: carimbo no
     * futuro. Quem nunca boostou (`null`) ou teve o boost vencido não casa.
     *
     * @param  Builder<PerformerProfile>  $query
     * @return Builder<PerformerProfile>
     */
    public function scopeBoosted(Builder $query): Builder
    {
        return $query->where('boosted_until', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tierGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tier_granted_by');
    }

    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    /**
     * A galeria pública de fotos (Sprint 10), sempre na ordem do carrossel.
     *
     * `orderBy('position')` na própria relação porque NÃO existe leitura da
     * galeria fora de ordem: card, carrossel, tela de edição e reordenação
     * consomem a mesma sequência. Ao contrário de `favorites` (que
     * deliberadamente NÃO tem relação inversa por ser bookmark privado), foto é
     * conteúdo público da performer — a relação direta é exatamente o que se
     * quer, e `withCount('photos')` no catálogo é métrica de vitrine legítima.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PerformerPhoto::class)->orderBy('position');
    }

    /**
     * As localizações da performer (Sprint 13), sempre na ordem de exibição.
     *
     * `orderBy('position')` na própria relação porque não há leitura fora de
     * ordem: card, perfil, tela de edição e o cache da primária consomem a mesma
     * sequência (primária em position 0). Escrita só pelo PerformerLocationService.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(PerformerLocation::class)->orderBy('position');
    }

    /**
     * A localização primária — a que alimenta o cache `state`/`city` e abre a
     * lista no card/perfil. Invariante "exatamente uma primária" é do
     * PerformerLocationService; aqui é só o atalho de leitura.
     */
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(PerformerLocation::class)->where('is_primary', true);
    }

    /**
     * As UFs públicas deste perfil, distintas e na ordem de exibição (primária
     * primeiro). É a ÚNICA porta pela qual a localização vira dado público — e
     * `city` NÃO passa por aqui, de propósito: é a mesma disciplina do
     * PerformerPublicResource, agora para a lista.
     *
     * Fallback para o cache `state` da coluna quando não há linha na tabela
     * (Sprint 9A não migrado, ou linha de teste criada direto), no mesmo espírito
     * do `activeWorlds()`. Lê da relação já carregada quando houver — o catálogo
     * faz eager load, e uma query por card devolveria o N+1 que o eager load
     * veio evitar.
     *
     * @return array<int, string>
     */
    public function locationStates(): array
    {
        $rows = $this->relationLoaded('locations')
            ? $this->locations
            : $this->locations()->get();

        if ($rows->isNotEmpty()) {
            return $rows->pluck('state')->unique()->values()->all();
        }

        return $this->state !== null ? [$this->state] : [];
    }

    /*
     * NÃO EXISTE `favorites()` AQUI, E NÃO É ESQUECIMENTO.
     *
     * Favorito (Sprint 10) é bookmark PRIVADO: a performer nunca sabe que foi
     * favoritada. `follows()` logo acima existe porque follow é o gesto público
     * — ela conta seguidores e, passado o Piso de Anonimato, vê a lista.
     *
     * A relação inversa é justamente a porta pela qual isso vazaria sem ninguém
     * decidir vazá-lo: com `$profile->favorites` no model, um
     * `withCount('favorites')` cabe numa linha dentro de qualquer query que a
     * performer consome, e um `favorites_count` aparece num resource dela como
     * se fosse métrica de vitrine. O contador não é dela.
     *
     * Quem precisa do outro sentido é o Hard Delete, e ele o faz por coluna, no
     * DeletionService (purgeFavoritesToOwnProfile) — sem relação e sem cascade,
     * que aqui nunca dispara porque os dois lados são soft-delete/anonimização
     * (item 11 do CLAUDE.md).
     */

    public function sentInterests(): HasMany
    {
        return $this->hasMany(PerformerInterest::class);
    }

    /**
     * As tags do perfil, na junção `performer_tag`.
     *
     * É hasMany e não belongsToMany porque não existe o outro lado: uma relação
     * many-to-many precisa de uma tabela `tags` para dar JOIN, e aqui o slug É o
     * valor — não há entidade a referenciar. O que a arquitetura pedia (junção
     * indexada em vez de json[], filtro por whereHas, escrita idempotente) está
     * inteiro; muda o nome da relação e o método de escrita, que é syncTags()
     * em vez do sync() do BelongsToMany.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(PerformerTag::class);
    }

    /**
     * Os slugs das tags deste perfil.
     *
     * Lê da relação já carregada quando houver — o catálogo faz eager load, e
     * uma query por card aqui devolveria o N+1 que a junção veio evitar.
     *
     * @return array<int, string>
     */
    public function tagSlugs(): array
    {
        return $this->relationLoaded('tags')
            ? $this->tags->pluck('tag_slug')->all()
            : $this->tags()->pluck('tag_slug')->all();
    }

    /**
     * Substitui o conjunto de tags do perfil, no espírito do sync() do
     * BelongsToMany: idempotente, e mexe só no que mudou.
     *
     * O diff não é otimização prematura — apagar tudo e reinserir faria toda
     * gravação de perfil (inclusive as que nem tocam em tag) queimar ids e
     * sujar o binlog. Ficar só no delta também mantém o índice único como rede:
     * reenviar a mesma seleção não tenta reinserir nada.
     *
     * @param  array<int, string>  $slugs  já validados contra allTags()
     */
    public function syncTags(array $slugs): void
    {
        $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));
        $current = $this->tags()->pluck('tag_slug')->all();

        $toAdd = array_diff($slugs, $current);
        $toRemove = array_diff($current, $slugs);

        if ($toAdd === [] && $toRemove === []) {
            return;
        }

        DB::transaction(function () use ($toAdd, $toRemove) {
            if ($toRemove !== []) {
                $this->tags()->whereIn('tag_slug', $toRemove)->delete();
            }

            if ($toAdd !== []) {
                $this->tags()->createMany(
                    array_map(fn (string $slug) => ['tag_slug' => $slug], array_values($toAdd)),
                );
            }
        });

        // A relação em memória ficou velha: quem leu $profile->tags antes do
        // sync veria a lista anterior no mesmo request (a resposta do update).
        $this->unsetRelation('tags');
    }

    /**
     * Regras de validação do nome artístico, num só lugar porque três Form
     * Requests o aceitam (cadastro web, cadastro API e edição de perfil) e a
     * unicidade precisa valer nos três — um deles de fora reabre o clone.
     *
     * Único porque o nome é a identidade comercial: sem isso, uma performer
     * verificada renomeia para o nome de outra, mantém o selo (o KYC valida a
     * identidade legal, não o nome artístico) e recebe as gorjetas dela.
     *
     * A collation da tabela é utf8mb4_unicode_ci, então a comparação já é
     * insensível a caixa e a acento ("Ana" == "ana" == "aná"). A regra unique
     * consulta a tabela crua, incluindo perfis soft-deleted — mesma escolha do
     * generateSlug(), para um nome não ser reciclado logo após uma saída.
     *
     * @return array<int, mixed>
     */
    public static function stageNameRules(?int $ignoreProfileId = null): array
    {
        $unique = Rule::unique('performer_profiles', 'stage_name');

        return ['string', 'max:255', $ignoreProfileId ? $unique->ignore($ignoreProfileId) : $unique];
    }

    public static function generateSlug(string $stageName): string
    {
        $base = Str::slug($stageName);
        do {
            $slug = $base.'-'.strtolower(Str::random(4));
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
