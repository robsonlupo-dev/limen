<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Um presente do catálogo FIXO da Limen (M.13.6). Preço em múltiplos de 4 tokens,
 * definido pela plataforma (não pela performer). Reference data — nasce e muda só
 * pelo GiftSeeder. `active` esconde do catálogo público sem apagar a linha (a FK
 * de gift_sends guarda o histórico dos envios já feitos).
 */
class Gift extends Model
{
    protected $fillable = ['name', 'slug', 'price_tokens', 'active'];

    protected function casts(): array
    {
        return [
            'price_tokens' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** Só presentes disponíveis para envio / exibição no catálogo. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
