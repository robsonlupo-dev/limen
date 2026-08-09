<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agendamento de chamada (feat/scheduled-call-v1). O membro reserva performer +
 * data/hora e trava um depósito; a performer confirma; na hora, os dois entram e a
 * chamada roda sobre uma `call_sessions type=private` (PR #140). Dona ÚNICA do ciclo
 * de vida + contabilidade do depósito: App\Services\CallReservationService — nenhuma
 * outra classe reserva, confirma, reembolsa, credita no-show ou pontua strike.
 *
 * `member_id` é CHAVE INTERNA (FK users), como no ledger; a exposição à performer é
 * SEMPRE via FanAlias (M.13.10) — nenhum resource/broadcast serializa member_id cru.
 *
 * Campos de MÁQUINA DE ESTADO (status, *_at, deposit_settled, strike_recorded,
 * room_name, call_session_id) ficam FORA do $fillable: só o CallReservationService
 * os escreve por forceFill após decisão de domínio, nunca de payload (disciplina de
 * discrete_mode/2FA/CallSession::type). `room_name` é $hidden (invariante #138).
 *
 * `deposit_settled` é o INVARIANTE de idempotência do depósito: todo movimento
 * one-time (refund / no-show credit / minuto-1) só ocorre com deposit_settled=false
 * e o marca true, sob lockForUpdate — reprocessar o cron nunca duplica saldo.
 */
class CallReservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW_MEMBER = 'no_show_member';

    public const STATUS_NO_SHOW_PERFORMER = 'no_show_performer';

    /** Estados "ativos" que contam para o teto por membro e ocupam o horário. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED];

    /**
     * Só o que a criação legítima precisa. A escrita de estado é do service via
     * forceFill (autoridade de domínio), nunca de request.
     */
    protected $fillable = [
        'member_id', 'performer_profile_id', 'scheduled_at', 'slot_minutes',
        'deposit_tokens', 'price_per_min_locked',
    ];

    /** room_name NUNCA sai serializado (invariante #138 — só viaja no JWT). */
    protected $hidden = ['room_name'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'slot_minutes' => 'integer',
            'deposit_tokens' => 'integer',
            'price_per_min_locked' => 'integer',
            'deposit_settled' => 'boolean',
            'strike_recorded' => 'boolean',
            'confirmed_at' => 'datetime',
            'performer_entered_at' => 'datetime',
            'member_entered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'slot_warning_sent_at' => 'datetime',
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

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * O instante-limite do COMPROMISSO (T-2h, ou T-30min se a reserva foi criada
     * com menos de 2h de antecedência). É o mesmo instante para as DUAS regras:
     *  - a performer precisa CONFIRMAR até aqui (senão cancela + refund);
     *  - o membro pode cancelar GRÁTIS até aqui (depois = no-show, depósito à performer).
     * Derivado de created_at/scheduled_at — nada a persistir.
     */
    public function commitmentDeadline(): Carbon
    {
        $windowHours = (int) config('scheduled_call.confirm_window_hours');
        $shortLeadMinutes = (int) config('scheduled_call.short_lead_confirm_minutes');

        $normalDeadline = $this->scheduled_at->copy()->subHours($windowHours);

        // Reserva criada com <2h de antecedência: não há janela de 2h, usa T-30min.
        if ($this->created_at !== null && $this->created_at->greaterThan($normalDeadline)) {
            return $this->scheduled_at->copy()->subMinutes($shortLeadMinutes);
        }

        return $normalDeadline;
    }

    /** A janela de confirmação da performer já venceu? (pending além do compromisso) */
    public function confirmWindowPassed(): bool
    {
        return now()->greaterThan($this->commitmentDeadline());
    }

    /** O membro ainda pode cancelar de graça (antes do compromisso)? */
    public function freeCancelAvailable(): bool
    {
        return now()->lessThanOrEqualTo($this->commitmentDeadline());
    }

    /** Fim do slot da chamada (a partir da entrada do membro). Null se não entrou. */
    public function slotEndsAt(): ?Carbon
    {
        return $this->member_entered_at?->copy()->addMinutes($this->slot_minutes);
    }

    /**
     * A performer pode ENTRAR agora? Janela [T-reminder, scheduled_at + join_window].
     * Fonte única lida pelo service (entrada) E pelo presenter (botão da UI).
     */
    public function performerEntryWindowOpen(): bool
    {
        $lead = (int) config('scheduled_call.reminder_lead_minutes');
        $joinWindow = (int) config('scheduled_call.join_window_seconds');

        return now()->greaterThanOrEqualTo($this->scheduled_at->copy()->subMinutes($lead))
            && now()->lessThanOrEqualTo($this->scheduled_at->copy()->addSeconds($joinWindow));
    }

    /**
     * O membro pode ENTRAR agora? Da entrada da performer até
     * `max(scheduled_at, performer_entered_at) + join_window`. O `max` impede que uma
     * performer entrando cedo (a partir de T-5min) encurte a janela do membro, que
     * conta com o HORÁRIO marcado — sem isso ela poderia forçar um no-show do membro
     * (e ficar com o depósito) só entrando 5min antes. Fonte única (service + presenter).
     */
    public function memberJoinWindowOpen(): bool
    {
        if ($this->performer_entered_at === null) {
            return false;
        }

        return now()->lessThanOrEqualTo($this->memberJoinDeadline());
    }

    /** Instante-limite da entrada do membro: max(horário, entrada da performer) + janela. */
    public function memberJoinDeadline(): Carbon
    {
        $joinWindow = (int) config('scheduled_call.join_window_seconds');
        $base = $this->performer_entered_at->greaterThan($this->scheduled_at)
            ? $this->performer_entered_at
            : $this->scheduled_at;

        return $base->copy()->addSeconds($joinWindow);
    }
}
