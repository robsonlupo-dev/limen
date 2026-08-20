<?php

namespace App\Models;

use App\Support\TokenMath;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'performer_id', 'tokens', 'amount_brl', 'pix_key', 'pix_key_type',
        'status', 'period_year', 'period_month', 'asaas_transfer_id', 'failure_reason',
        'requested_at', 'processed_at', 'unresolved_since',
    ];

    protected function casts(): array
    {
        return [
            'amount_brl' => 'decimal:2',
            'pix_key' => 'encrypted',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'unresolved_since' => 'datetime',
        ];
    }

    // DECIMAL(20,4) desde a economia decimal: o payout consome uma quantidade
    // fracionária de token (conversão floored ao centavo — R2 —, sobra preservada —
    // R3). Lê INT quando inteiro ("500.0000" → 500), string decimal quando fracionário.
    protected function tokens(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_id');
    }
}
