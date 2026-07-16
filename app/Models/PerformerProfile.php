<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    protected $fillable = [
        'stage_name', 'slug', 'bio', 'category', 'work_modes',
        'level', 'split_pct', 'rate_public', 'rate_private', 'rate_camera',
        'is_live', 'is_verified', 'avatar_path', 'cover_path',
    ];

    protected function casts(): array
    {
        return [
            'work_modes' => 'array',
            'is_live' => 'boolean',
            'is_verified' => 'boolean',
            'rating_avg' => 'decimal:2',
            'split_pct' => 'integer',
            'rate_public' => 'integer',
            'rate_private' => 'integer',
            'rate_camera' => 'integer',
            'rating_count' => 'integer',
            'followers_count' => 'integer',
        ];
    }

    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->whereNotNull('slug');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            $slug = $base . '-' . strtolower(Str::random(4));
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
