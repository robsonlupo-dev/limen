<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const LANGUAGES = [
        'portugues', 'ingles', 'espanhol', 'frances', 'italiano', 'alemao', 'japones',
    ];

    public const DRINKS = ['nao_bebe', 'bebe_socialmente', 'bebe_frequentemente'];

    public const SMOKES = ['nao_fuma', 'fuma_socialmente', 'fuma'];

    /** Faixa do slider de altura, em cm. Espelhada na tela e no Form Request. */
    public const HEIGHT_MIN_CM = 140;

    public const HEIGHT_MAX_CM = 190;

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
    ];

    protected function casts(): array
    {
        return [
            'worlds' => 'array',
            'work_modes' => 'array',
            'languages' => 'array',
            'height_cm' => 'integer',
            'is_live' => 'boolean',
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
            ->with(['user', 'tags'])
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->whereNotNull('slug');
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
