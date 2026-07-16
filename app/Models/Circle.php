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
}
