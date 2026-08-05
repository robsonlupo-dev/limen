<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participação de UM membro num group show (Sprint 15, PR #141). Cada linha tem
 * a própria cobrança independente: `price_per_minute` (snapshot do preço-grupo no
 * join; vira o preço 1:1 se este for o sobrevivente de um upgrade) e `minutes_billed`
 * (fonte única de idempotência, mesmo mecanismo do 1:1). Dona única: GroupShowService.
 *
 * `member_id` é chave interna; a performer só vê o FanAlias. `status`,
 * `minutes_billed`, `price_per_minute` (após o join), `joined_at`/`ended_at` são
 * autoridade do service (forceFill) — só o mínimo da criação legítima entra no
 * $fillable.
 */
class CallSessionParticipant extends Model
{
    protected $fillable = [
        'call_session_id', 'member_id', 'price_per_minute',
    ];

    protected function casts(): array
    {
        return [
            'price_per_minute' => 'integer',
            'minutes_billed' => 'integer',
            'joined_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
