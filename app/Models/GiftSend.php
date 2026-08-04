<?php

namespace App\Models;

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
            'tokens' => 'integer',
            'performer_amount' => 'integer',
            'platform_amount' => 'integer',
            'applied_rate' => 'integer',
            'sender_ledger_id' => 'integer',
            'performer_ledger_id' => 'integer',
        ];
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
