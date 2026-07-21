<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circle extends Model
{
    /**
     * Tier order, lowest to highest. Used by the `circle` middleware / gate to
     * resolve "prestige or higher". Single source of truth for ranking.
     */
    public const TIER_ORDER = ['explorador', 'insider', 'prestige', 'black', 'founders_circle'];

    protected $fillable = [
        'slug', 'name', 'price_cents', 'monthly_tokens', 'discount_pct',
        'seat_limit', 'invite_only', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'monthly_tokens' => 'integer',
            'discount_pct' => 'integer',
            'seat_limit' => 'integer',
            'invite_only' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Rank within TIER_ORDER (0-based), or -1 if unknown. */
    public function tierRank(): int
    {
        return array_search($this->slug, self::TIER_ORDER, true) === false
            ? -1
            : (int) array_search($this->slug, self::TIER_ORDER, true);
    }

    /**
     * Este Círculo é de tier `$minSlug` ou superior?
     *
     * Dona única da comparação de tier. Existe porque os call sites vinham
     * escrevendo a regra à mão com `array_search`, e dois deles erravam do jeito
     * caro: `tierRank() >= array_search('black', TIER_ORDER, true)` falha ABERTO
     * se 'black' sair do TIER_ORDER (renomeação, reordenação). `array_search`
     * devolve `false`, e numa comparação com bool o PHP converte os DOIS lados —
     * `3 >= false`, `0 >= false` e até `-1 >= false` são todos true. O gate
     * deixava de restringir qualquer coisa em vez de barrar tudo.
     *
     * Fail-closed nas duas pontas: slug desconhecido de um lado ou de outro
     * responde `false`. Num gate de privilégio, "não sei comparar" é "não".
     *
     * Comparação por RANK e não por lista de slugs: tier novo acima de Black
     * herda o privilégio sem precisar editar cada service.
     */
    public function tierAtLeast(string $minSlug): bool
    {
        $mine = array_search($this->slug, self::TIER_ORDER, true);
        $min = array_search($minSlug, self::TIER_ORDER, true);

        if ($mine === false || $min === false) {
            return false;
        }

        return $mine >= $min;
    }
}
