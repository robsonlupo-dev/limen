<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chamada privada 1:1 (Sprint 15, PR #140). Tabela criada no #138. A chamada é
 * PAGA por minuto: o membro debita `spend_call` e a performer credita `call_credit`
 * (split 70/30, applied_rate=70). Dona única do ciclo de vida + cobrança:
 * App\Services\CallService — nenhuma outra classe cobra ou cria sala de chamada.
 *
 * `member_id` é CHAVE INTERNA (FK users), como no ledger — a disciplina do FanAlias
 * é sobre APRESENTAÇÃO: NENHUM resource/broadcast da performer serializa member_id
 * cru; ela vê o FanAlias label (a identity da sala já é o handle por par). M.13.10:
 * a performer nunca vê tier nem saldo do membro.
 *
 * `type` fica FORA do $fillable de propósito (achado 🔴 da revisão do plano): a
 * rota 1:1 escreve a constante TYPE_PRIVATE pelo service, nunca do request — senão
 * um membro postaria type=group e furaria o teto de 2 participantes. Mesma
 * disciplina de discrete_mode/is_invite/2FA.
 *
 * `room_name` (LiveKit) é imprevisível e UNIQUE — NÃO vaza em URL/prop/log
 * (invariante do #138), só viaja dentro do JWT curto.
 *
 * `status` pending→active→ended|declined|expired vence na LEITURA (reconcilePending):
 * pending sem resposta em 60s → expired. Só `active` é servível — todo o resto é
 * recusado (fail-closed).
 */
class CallSession extends Model
{
    public const TYPE_PRIVATE = 'private';

    public const TYPE_GROUP = 'group';

    /** Segundos que uma chamada pending espera resposta antes de expirar. */
    public const PENDING_TTL_SECONDS = 60;

    /**
     * `type`, `status`, `minutes_billed`, `started_at`/`ended_at` ficam FORA:
     * autoridade do CallService (forceFill após decisão de domínio), nunca de
     * payload. Só o que a criação legítima precisa entra aqui.
     */
    protected $fillable = [
        'performer_profile_id', 'member_id', 'room_name',
        'price_per_minute', 'max_duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'price_per_minute' => 'integer',
            'minutes_billed' => 'integer',
            'max_duration_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function performerProfile(): BelongsTo
    {
        return $this->belongsTo(PerformerProfile::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /** Chamadas que "ocupam" a performer/membro: pending OU active. */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'active']);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** A janela de resposta de 60s já passou? (pending vencido) */
    public function pendingExpired(): bool
    {
        return $this->isPending()
            && $this->created_at !== null
            && $this->created_at->getTimestamp() + self::PENDING_TTL_SECONDS < now()->getTimestamp();
    }
}
