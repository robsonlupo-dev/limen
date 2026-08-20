<?php

namespace App\Models;

use App\Support\TokenMath;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro imutável de um presente enviado (M.13.6). Nasce só no GiftService, sob
 * os locks das duas carteiras. Espelha `Tip`: lastro fiscal, split congelado.
 *
 * $hidden sender_id: o membro NUNCA vaza para a performer (M.13.10). As
 * superfícies dela usam FanAlias (handle/label), nunca esta coluna.
 */
class GiftSend extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'sender_id', 'performer_profile_id', 'gift_id', 'tokens',
        'performer_amount', 'platform_amount', 'applied_rate',
        'idempotency_key', 'sender_ledger_id', 'performer_ledger_id',
    ];

    protected $hidden = ['sender_id'];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer', // bruto: membro paga inteiro
            'applied_rate' => 'integer',
            'sender_ledger_id' => 'integer',
            'performer_ledger_id' => 'integer',
        ];
    }

    // Espelho do split fraciona (75% de 3 = 2,25) desde 19/08/2026. Contrato
    // uniforme: INT quando inteiro ("30.00" → 30), STRING decimal quando fracionário.
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
            throw new \RuntimeException('GiftSend records are immutable.');
        });
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
