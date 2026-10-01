<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assinatura recorrente do Fã-Clube de uma performer (Onda 4, docs/FORK_ASSINATURA.md
 * §1/§4/§9). Relação NOVA, separada do Círculo global (Premissa 0). Paga em token.
 *
 * Dona ÚNICA do ciclo + contabilidade: App\Services\FanclubService. Estado (status,
 * períodos, grace, ledger id) fica FORA do $fillable — só o service escreve (forceFill).
 *
 * `member_id` é chave INTERNA (FK users); a exposição à performer é SEMPRE por FanAlias
 * (nunca id/nome). Acesso ao set de fã-clube (serving) = `status = active`.
 */
class FanclubMembership extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'price_tokens' => 'integer',
            'cancel_requested' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
