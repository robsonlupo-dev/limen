<?php

namespace App\Models;

use App\Support\TokenMath;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tip extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'consumer_id', 'performer_profile_id', 'amount', 'performer_amount',
        'platform_amount', 'message', 'idempotency_key',
        'consumer_ledger_id', 'performer_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', // bruto: membro paga inteiro
        ];
    }

    // Espelho do split fraciona (80% de 3 = 2,40) desde 19/08/2026. Contrato
    // uniforme: INT quando inteiro ("40.00" → 40), STRING decimal quando fracionário.
    protected function performerAmount(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
    }

    protected function platformAmount(): Attribute
    {
        return Attribute::make(get: fn ($value) => TokenMath::readable($value ?? 0));
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Tip records are immutable.');
        });
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function consumerLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'consumer_ledger_id');
    }

    public function performerLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'performer_ledger_id');
    }
}
