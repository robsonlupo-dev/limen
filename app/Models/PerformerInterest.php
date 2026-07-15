<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerInterest extends Model
{
    protected $fillable = [
        'performer_profile_id', 'member_id', 'status',
        'sent_at', 'unlocked_at', 'unlock_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function isUnlocked(): bool
    {
        return $this->status === 'unlocked';
    }

    /**
     * Interesse enviado a um membro que optou por sair. Existe apenas para que
     * cooldown e limite diário contem (sem vazar o opt-out à performer) e é
     * invisível ao membro — nunca listar nem permitir desbloqueio.
     */
    public function isSuppressed(): bool
    {
        return $this->status === 'suppressed';
    }

    /** Interesses que o membro pode de fato ver na caixa dele. */
    public function scopeVisibleToMember(Builder $query): Builder
    {
        return $query->where('status', '!=', 'suppressed');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function unlockLedger(): BelongsTo
    {
        return $this->belongsTo(TokenLedger::class, 'unlock_ledger_id');
    }
}
