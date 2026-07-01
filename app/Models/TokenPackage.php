<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenPackage extends Model
{
    protected $fillable = [
        'slug', 'name', 'tokens', 'bonus', 'price_cents', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'bonus' => 'integer',
            'price_cents' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
