<?php

namespace App\Services;

use App\Events\CallReservationCallStarted;
use App\Events\CallReservationPerformerEntered;
use App\Events\CallReservationReminder;
use App\Events\CallReservationResolved;
use App\Events\CallReservationSlotWarning;
use App\Exceptions\CallReservationException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\CallReservation;
use App\Models\CallSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\FanAlias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dona ÚNICA do agendamento de chamada (feat/scheduled-call-v1): reserva, trava do
 * depósito, confirmação, cancelamento, entrada dos dois lados, no-show, refund e
 * strike. Nenhuma outra classe move o depósito de uma reserva.
 *
 * Reusa o motor do PR #140: quando os dois entram, cria/liga uma `call_sessions
 * type=private` e a cobrança dos minutos 2+ é 100% do CallService (heartbeat/
 * token-refresh/end sobre aquela sessão). O 1º minuto é pago pelo DEPÓSITO travado
 * na reserva (crédito `call_credit` 70/30 à performer, sem novo débito do membro).
 *
 * ── Economia (ledger append-only, princípio nº 2; saldo nunca negativo) ─────────
 *  - Reserva → `spend_call_reservation` (débito do depósito, trava os tokens).
 *  - Entrada OK → `call_credit` 70/30 (o depósito vira o minuto 1).
 *  - No-show do MEMBRO → `call_noshow_credit` 100/0 à performer (ganho sacável).
 *  - Cancel grátis / não-confirmada / no-show da PERFORMER → `call_reservation_refund`
 *    100% ao membro (nunca respeita teto; fora do payout).
 *
 * ── Idempotência ────────────────────────────────────────────────────────────────
 * Todo movimento one-time do depósito roda sob `CallReservation::lockForUpdate()` e
 * é guardado por `deposit_settled`: reprocessar o cron/duplo-submit nunca duplica
 * saldo. Os broadcasts saem DEPOIS que a `DB::transaction()` retorna (o commit já
 * aconteceu) — nunca no rollback, que joga antes da linha de dispatch (precedente
 * do CallService; equivale ao afterCommit e roda sob RefreshDatabase nos testes).
 *
 * ── Anti-oráculo / M.13.10 ──────────────────────────────────────────────────────
 * Reserva de terceiro, inexistente ou em estado incompatível → o MESMO 404. A
 * performer nunca vê member_id/tier/saldo — só o FanAlias label na fila de reservas.
 */
class CallReservationService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
        private LiveKitService $livekit,
    ) {}

    // ── Reserva (membro) ────────────────────────────────────────────────────────

    /**
     * O membro agenda performer + horário. Valida disponibilidade e janela de tempo,
     * TRAVA o depósito (débito do preço de 1 min, congelado), e cria a reserva
     * pending — tudo sob lock do perfil (buffer/colisão) e da linha do membro (teto).
     */
    public function reserve(User $member, PerformerProfile $profile, Carbon $scheduledAt): CallReservation
    {
        // Auto-agendamento não faz sentido; 404 uniforme (não confirma nada do par).
        if ($member->id === $profile->user_id) {
            throw CallReservationException::notFound();
        }

        $performerUser = $profile->user;
        if (! $profile->is_verified
            || $performerUser === null
            || $performerUser->status !== 'active'
            || $profile->call_price_per_minute === null) {
            throw CallReservationException::unavailable();
        }

        $this->assertWithinBookingWindow($scheduledAt);

        $price = (int) $profile->call_price_per_minute;
        $slotMinutes = $this->slotMinutesFor($profile);

        if ($this->tokenService->balance($member) < $price) {
            throw CallReservationException::insufficientBalance();
        }

        return DB::transaction(function () use ($member, $profile, $scheduledAt, $price, $slotMinutes) {
            // Ordem de lock estável (perfil → membro) para não deadlockar com outra
            // reserva concorrente do mesmo membro (o membro é sempre o 2º lock).
            $lockedProfile = PerformerProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            User::whereKey($member->id)->lockForUpdate()->first();

            // Teto DURO de agendamentos ativos por membro (pending+confirmed), imposto
            // sob o lock da linha do membro (como buscas salvas — SavedSearchService).
            $activeCount = CallReservation::where('member_id', $member->id)->active()->count();
            if ($activeCount >= (int) config('scheduled_call.max_active_per_member')) {
                throw CallReservationException::limit();
            }

            // Buffer/colisão: o novo bloco [start, start+slot] precisa de buffer de
            // ambos os lados contra todo bloco ativo da MESMA performer. Sob o lock
            // do perfil, duas reservas concorrentes para horários adjacentes serializam.
            if ($this->hasSlotConflict($lockedProfile->id, $scheduledAt, $slotMinutes)) {
                throw CallReservationException::slotUnavailable();
            }

            $reservation = new CallReservation([
                'member_id' => $member->id,
                'performer_profile_id' => $lockedProfile->id,
                'scheduled_at' => $scheduledAt,
                'slot_minutes' => $slotMinutes,
                'deposit_tokens' => $price,
                'price_per_min_locked' => $price,
            ]);
            $reservation->forceFill(['status' => CallReservation::STATUS_PENDING])->save();

            // Depósito PRÉ-PAGO: sem saldo, sem reserva. O débito re-checa o saldo sob
            // o lock do wallet (TokenService::debit) — saldo < 0 é impossível. Referencia
            // a reserva recém-criada (auditoria); a race de saldo reverte tudo (a
            // reserva sai junto por estarem na mesma transação).
            try {
                $this->tokenService->debit(
                    $member,
                    $price,
                    'spend_call_reservation',
                    CallReservation::class,
                    $reservation->id,
                    'Depósito de agendamento de chamada',
                );
            } catch (InsufficientBalanceException) {
                throw CallReservationException::insufficientBalance();
            }

            return $reservation;
        });
    }

    // ── Confirmação (performer, MANUAL) ───────────────────────────────────────────

    /** A performer confirma a reserva. Sob lock: relê dono/estado/janela. */
    public function confirm(User $performerUser, CallReservation $reservation): void
    {
        DB::transaction(function () use ($performerUser, $reservation) {
            $locked = $this->lockOwnedByPerformer($reservation->id, $performerUser);

            if (! $locked->isPending()) {
                // Já confirmada/cancelada/resolvida → não reconfirma (410 se terminal).
                throw CallReservationException::gone();
            }
            if ($locked->confirmWindowPassed()) {
                // Passou de T-2h sem confirmar: o cron vai cancelar+refund; não deixa
                // confirmar em cima da hora (a garantia do membro é o silêncio = refund).
                throw CallReservationException::gone();
            }

            $locked->forceFill([
                'status' => CallReservation::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ])->save();
        });
    }

    /**
     * A performer RECUSA uma reserva pending → refund integral ao membro, sem strike
     * (recusar é legítimo; strike é só para quem confirma e não aparece). Idempotente.
     */
    public function decline(User $performerUser, CallReservation $reservation): void
    {
        $memberId = DB::transaction(function () use ($performerUser, $reservation) {
            $locked = $this->lockOwnedByPerformer($reservation->id, $performerUser);
            if (! $locked->isPending()) {
                throw CallReservationException::gone();
            }
            $this->settleRefund($locked, CallReservation::STATUS_CANCELLED, strike: false);

            return $locked->member_id;
        });

        CallReservationResolved::dispatch($memberId, $reservation->id, 'refunded_not_confirmed');
    }

    // ── Cancelamento (membro) ─────────────────────────────────────────────────────

    /**
     * O membro cancela. Pending → refund sempre (a performer não se comprometeu).
     * Confirmada → refund se antes de T-2h; depois, o depósito vira da performer
     * (no-show do membro — a contrapartida do compromisso). Idempotente por status.
     */
    public function cancel(User $member, CallReservation $reservation): void
    {
        [$recipientUserId, $outcome, $performerUserId] = DB::transaction(function () use ($member, $reservation) {
            $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->member_id !== $member->id) {
                throw CallReservationException::notFound();
            }
            if (! in_array($locked->status, CallReservation::ACTIVE_STATUSES, true)) {
                throw CallReservationException::gone();
            }

            // Confirmada + já passou o compromisso → forfeit à performer.
            if ($locked->isConfirmed() && ! $locked->freeCancelAvailable()) {
                $this->settleNoShowMember($locked);

                return [
                    $locked->performerProfile->user_id,
                    'no_show_member',
                    $locked->performerProfile->user_id,
                ];
            }

            // Pending (qualquer hora) OU confirmada dentro da janela → refund grátis.
            $this->settleRefund($locked, CallReservation::STATUS_CANCELLED, strike: false);

            return [$member->id, 'cancelled', null];
        });

        // Broadcast pós-transação: o membro sabe do cancelamento; a performer é
        // avisada no forfeit (o depósito é dela). Um rollback teria jogado antes.
        CallReservationResolved::dispatch($recipientUserId, $reservation->id, $outcome);
    }

    // ── Entrada da performer (ela abre a sala) ────────────────────────────────────

    /**
     * A performer entra primeiro — cria a sala LiveKit (não desperdiça sessão antes
     * disso) e abre a janela de join do membro. Idempotente: reentrar devolve novo
     * JWT para a MESMA sala. Fora da janela de entrada → 409.
     *
     * @return array{token:string, wsUrl:string}
     */
    public function performerEnter(User $performerUser, CallReservation $reservation): array
    {
        $result = DB::transaction(function () use ($performerUser, $reservation) {
            $locked = $this->lockOwnedByPerformer($reservation->id, $performerUser);

            if (! $locked->isConfirmed()) {
                throw CallReservationException::gone();
            }
            if (! $locked->performerEntryWindowOpen()) {
                throw CallReservationException::notJoinable();
            }

            // Reentrada idempotente: já entrou → só devolve novo token da MESMA sala.
            if ($locked->performer_entered_at !== null && $locked->room_name !== null) {
                return ['room' => $locked->room_name, 'notify' => false];
            }

            $roomName = $this->livekit->callRoomName();
            // createRoom é chamada de rede DENTRO da transação (padrão do CallService):
            // se falhar, o performer_entered_at/room_name não são gravados.
            $this->livekit->createRoom($roomName, (int) config('livekit.max_participants_call'));

            $locked->forceFill([
                'room_name' => $roomName,
                'performer_entered_at' => now(),
            ])->save();

            return ['room' => $roomName, 'notify' => true, 'memberUserId' => $locked->member_id];
        });

        if ($result['notify']) {
            CallReservationPerformerEntered::dispatch(
                $result['memberUserId'],
                $reservation->id,
                (int) config('scheduled_call.join_window_seconds'),
            );
        }

        return $this->tokenBundle(
            $this->livekit->performerIdentity($reservation->performer_profile_id),
            LiveKitService::ROLE_PERFORMER,
            $result['room'],
        );
    }

    // ── Entrada do membro (converte o depósito no minuto 1) ────────────────────────

    /**
     * O membro entra na janela de join. Cria a `call_sessions type=private` ATIVA
     * (minuto 1 já pago pelo depósito → crédito 70/30 à performer, sem novo débito),
     * liga na reserva e devolve o JWT do membro. Dos minutos 2+ em diante, o front
     * usa as rotas existentes do PR #140 (call.heartbeat/token-refresh/end).
     *
     * @return array{call_id:int, token:string, wsUrl:string}
     */
    public function memberEnter(User $member, CallReservation $reservation): array
    {
        $result = DB::transaction(function () use ($member, $reservation) {
            $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->member_id !== $member->id) {
                throw CallReservationException::notFound();
            }

            // Reentrada idempotente: já entrou e a sessão segue ativa → novo token da
            // mesma sala, sem recriar sessão nem re-creditar (deposit_settled já true).
            if ($locked->status === CallReservation::STATUS_COMPLETED && $locked->call_session_id !== null) {
                $session = CallSession::find($locked->call_session_id);
                if ($session !== null && $session->isActive()) {
                    return ['call_id' => $session->id, 'room' => $session->room_name];
                }

                // A chamada já encerrou (saldo/tempo/fim) — não reabre.
                throw CallReservationException::gone();
            }

            if (! $locked->isConfirmed() || $locked->performer_entered_at === null || $locked->room_name === null) {
                throw CallReservationException::notJoinable();
            }
            if (! $locked->memberJoinWindowOpen()) {
                throw CallReservationException::notJoinable();
            }

            $performerUser = $locked->performerProfile?->user;
            if ($performerUser === null || $performerUser->status !== 'active' || $member->status !== 'active') {
                throw CallReservationException::gone();
            }

            // Exclusividade do membro: não pode estar em outra chamada 1:1/group ativa
            // (dois streams de vídeo cobrados em paralelo) — fonte única do PR #140.
            User::whereKey($member->id)->lockForUpdate()->first();
            if (CallService::memberIsBusy($member->id)) {
                throw CallReservationException::notJoinable();
            }

            $price = (int) $locked->price_per_min_locked;
            $maxDuration = $this->maxDurationForCall($locked);

            $session = new CallSession([
                'performer_profile_id' => $locked->performer_profile_id,
                'member_id' => $member->id,
                'room_name' => $locked->room_name,
                'price_per_minute' => $price,
                'max_duration_minutes' => $maxDuration,
            ]);
            $session->forceFill([
                'type' => CallSession::TYPE_PRIVATE,
                'status' => 'active',
                'started_at' => now(),
                'minutes_billed' => 1, // o minuto 1 está pago pelo depósito
            ])->save();

            // O depósito vira o minuto 1: crédito 70/30 à performer, SEM novo débito
            // (o membro já pagou no spend_call_reservation). Guardado por deposit_settled.
            $this->settleMinuteOne($locked, $member, $performerUser, $session->id);

            $locked->forceFill([
                'status' => CallReservation::STATUS_COMPLETED,
                'member_entered_at' => now(),
                'completed_at' => now(),
                'call_session_id' => $session->id,
            ])->save();

            return [
                'call_id' => $session->id,
                'room' => $session->room_name,
                'performer_user_id' => $performerUser->id,
                'started' => true,
            ];
        });

        // Avisa a performer (já na sala) do call_id para ela renovar/encerrar pelas
        // rotas do #140. Só na PRIMEIRA entrada (reentrada idempotente não tem
        // 'started'). Pós-transação: um rollback teria jogado antes.
        if ($result['started'] ?? false) {
            CallReservationCallStarted::dispatch(
                $result['performer_user_id'],
                $reservation->id,
                $result['call_id'],
            );
        }

        return [
            'call_id' => $result['call_id'],
            'token' => $this->tokenBundle(
                $this->livekit->callMemberIdentity($reservation->performer_profile_id, $member->id),
                LiveKitService::ROLE_MEMBER,
                $result['room'],
            )['token'],
            'wsUrl' => (string) config('livekit.url'),
        ];
    }

    // ── Cron (ProcessCallReservations) ────────────────────────────────────────────

    /**
     * Cancela+refund as reservas PENDING cuja janela de confirmação venceu (silêncio
     * da performer = devolução — a garantia do membro). Idempotente por reserva.
     */
    public function expireUnconfirmed(): int
    {
        $count = 0;
        $due = CallReservation::where('status', CallReservation::STATUS_PENDING)
            ->where('scheduled_at', '<=', now()->addHours((int) config('scheduled_call.confirm_window_hours')))
            ->with('performerProfile.user')
            ->get();

        foreach ($due as $reservation) {
            $handled = DB::transaction(function () use ($reservation) {
                $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isPending() || ! $locked->confirmWindowPassed()) {
                    return null;
                }
                $this->settleRefund($locked, CallReservation::STATUS_CANCELLED, strike: false);

                return $locked->member_id;
            });

            if ($handled !== null) {
                $count++;
                CallReservationResolved::dispatch($handled, $reservation->id, 'refunded_not_confirmed');
            }
        }

        return $count;
    }

    /**
     * Resolve os no-shows das reservas CONFIRMED cujo horário chegou:
     *  - performer não entrou até scheduled_at + join_window → no_show_performer
     *    (refund ao membro + strike);
     *  - performer entrou mas o membro não entrou na janela → no_show_member
     *    (depósito 100% à performer).
     * Idempotente por reserva (deposit_settled).
     */
    public function resolveNoShows(): int
    {
        $joinWindow = (int) config('scheduled_call.join_window_seconds');
        $count = 0;

        // Confirmadas cuja janela venceu de QUALQUER lado: scheduled_at + join_window
        // (performer não entrou) OU performer_entered_at + join_window (membro não
        // entrou, mesmo que a performer tenha entrado cedo).
        $threshold = now()->subSeconds($joinWindow);
        $due = CallReservation::where('status', CallReservation::STATUS_CONFIRMED)
            ->where(function ($q) use ($threshold) {
                $q->where('scheduled_at', '<=', $threshold)
                    ->orWhere(fn ($q2) => $q2->whereNotNull('performer_entered_at')
                        ->where('performer_entered_at', '<=', $threshold));
            })
            ->with('performerProfile.user')
            ->get();

        foreach ($due as $reservation) {
            $resolution = DB::transaction(function () use ($reservation, $joinWindow) {
                $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isConfirmed()) {
                    return null;
                }

                // Performer entrou: a bola está com o membro. Só resolve como no-show
                // do membro depois que a janela DELE (max(horário, entrada dela) +
                // janela) vence — o mesmo prazo que memberJoinWindowOpen usa.
                if ($locked->performer_entered_at !== null) {
                    if (now()->lessThanOrEqualTo($locked->memberJoinDeadline())) {
                        return null; // ainda dentro da janela do membro
                    }
                    $this->settleNoShowMember($locked);
                    $this->safeDeleteRoom($locked->room_name);

                    return ['user' => $locked->performerProfile->user_id, 'outcome' => 'no_show_member'];
                }

                // Performer NÃO entrou até scheduled_at + join_window → no-show dela.
                if (now()->greaterThan($locked->scheduled_at->copy()->addSeconds($joinWindow))) {
                    $this->settleRefund($locked, CallReservation::STATUS_NO_SHOW_PERFORMER, strike: true);

                    return ['user' => $locked->member_id, 'outcome' => 'refunded_no_show_performer'];
                }

                return null;
            });

            if ($resolution !== null) {
                $count++;
                CallReservationResolved::dispatch($resolution['user'], $reservation->id, $resolution['outcome']);
            }
        }

        return $count;
    }

    /** Aviso T-5min aos dois lados. Idempotente por reminder_sent_at. */
    public function sendReminders(): int
    {
        $lead = (int) config('scheduled_call.reminder_lead_minutes');
        $count = 0;

        $due = CallReservation::where('status', CallReservation::STATUS_CONFIRMED)
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at', [now(), now()->addMinutes($lead)])
            ->with('performerProfile.user')
            ->get();

        foreach ($due as $reservation) {
            $sent = DB::transaction(function () use ($reservation) {
                $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isConfirmed() || $locked->reminder_sent_at !== null) {
                    return false;
                }
                $locked->forceFill(['reminder_sent_at' => now()])->save();

                return true;
            });

            if ($sent) {
                $count++;
                $performerUserId = $reservation->performerProfile->user_id;
                $memberUserId = $reservation->member_id;
                $minutesUntil = max(0, (int) ceil(now()->diffInSeconds($reservation->scheduled_at, false) / 60));
                $memberLabel = FanAlias::label($reservation->performer_profile_id, $reservation->member_id);
                $performerName = $reservation->performerProfile->stage_name;

                // À performer: FanAlias do membro. Ao membro: stage_name da performer.
                CallReservationReminder::dispatch($performerUserId, $reservation->id, $memberLabel, $minutesUntil);
                CallReservationReminder::dispatch($memberUserId, $reservation->id, $performerName, $minutesUntil);
            }
        }

        return $count;
    }

    /**
     * Aviso T-3min do fim do slot à PERFORMER, só quando há PRÓXIMO agendamento na
     * fila (senão a chamada é flexível e não há por que avisar). O membro não vê.
     * Idempotente por slot_warning_sent_at.
     */
    public function sendSlotWarnings(): int
    {
        $warnLead = (int) config('scheduled_call.next_slot_warning_minutes');
        $count = 0;

        // Reservas em curso (membro entrou) com sessão ainda ativa e próximo agendamento.
        $inProgress = CallReservation::where('status', CallReservation::STATUS_COMPLETED)
            ->whereNull('slot_warning_sent_at')
            ->whereNotNull('member_entered_at')
            ->whereNotNull('call_session_id')
            ->with('performerProfile.user')
            ->get();

        foreach ($inProgress as $reservation) {
            $slotEnd = $reservation->slotEndsAt();
            if ($slotEnd === null) {
                continue;
            }
            // Fora da faixa [slotEnd - warnLead, slotEnd] → ainda não / já passou.
            if (now()->lessThan($slotEnd->copy()->subMinutes($warnLead)) || now()->greaterThan($slotEnd)) {
                continue;
            }
            $session = CallSession::find($reservation->call_session_id);
            if ($session === null || ! $session->isActive()) {
                continue;
            }
            if (! $this->hasNextAppointment($reservation)) {
                continue;
            }

            $sent = DB::transaction(function () use ($reservation) {
                $locked = CallReservation::whereKey($reservation->id)->lockForUpdate()->first();
                if ($locked === null || $locked->slot_warning_sent_at !== null) {
                    return false;
                }
                $locked->forceFill(['slot_warning_sent_at' => now()])->save();

                return true;
            });

            if ($sent) {
                $count++;
                $performerUserId = $reservation->performerProfile->user_id;
                $minutesLeft = max(0, (int) ceil(now()->diffInSeconds($slotEnd, false) / 60));
                CallReservationSlotWarning::dispatch($performerUserId, $reservation->id, $minutesLeft);
            }
        }

        return $count;
    }

    // ── Internos: contabilidade do depósito (idempotente por deposit_settled) ─────

    /**
     * Refund 100% ao membro (`call_reservation_refund` — nunca respeita teto, fora do
     * payout). Opcionalmente aplica strike à performer (no-show dela). Só age com
     * deposit_settled=false; marca settled + status. PRESSUPÕE $locked travado.
     */
    private function settleRefund(CallReservation $locked, string $newStatus, bool $strike): void
    {
        if ($locked->deposit_settled) {
            return;
        }

        $member = $locked->member;
        if ($member !== null) {
            $this->creditPolicy->credit(
                $member,
                (int) $locked->deposit_tokens,
                'call_reservation_refund',
                CallReservation::class,
                $locked->id,
                'Reembolso de agendamento de chamada',
            );
        }

        $locked->forceFill([
            'deposit_settled' => true,
            'status' => $newStatus,
            'cancelled_at' => now(),
        ])->save();

        if ($strike) {
            $this->recordStrike($locked);
        }
    }

    /**
     * No-show do membro: o depósito vira 100% da performer (`call_noshow_credit`,
     * applied_rate=100 congelado — ganho sacável). Só age com deposit_settled=false.
     * PRESSUPÕE $locked travado.
     */
    private function settleNoShowMember(CallReservation $locked): void
    {
        if ($locked->deposit_settled) {
            return;
        }

        $performerUser = $locked->performerProfile?->user;
        if ($performerUser !== null) {
            $this->creditPolicy->creditWithSplit(
                $performerUser,
                (int) $locked->deposit_tokens,
                'call_noshow',
                'call_noshow_credit',
                CallReservation::class,
                $locked->id,
                'No-show de agendamento de '.FanAlias::label($locked->performer_profile_id, $locked->member_id),
            );
        }

        $locked->forceFill([
            'deposit_settled' => true,
            'status' => CallReservation::STATUS_NO_SHOW_MEMBER,
        ])->save();
    }

    /**
     * O minuto 1 é pago pelo depósito: crédito 70/30 à performer (`call_credit`,
     * applied_rate=70), SEM novo débito do membro. Só age com deposit_settled=false.
     * PRESSUPÕE $locked travado.
     */
    private function settleMinuteOne(CallReservation $locked, User $member, User $performerUser, int $callSessionId): void
    {
        if ($locked->deposit_settled) {
            return;
        }

        $this->creditPolicy->creditWithSplit(
            $performerUser,
            (int) $locked->price_per_min_locked,
            'call',
            'call_credit',
            CallSession::class,
            $callSessionId,
            'Chamada agendada de '.FanAlias::label($locked->performer_profile_id, $member->id),
        );

        $locked->forceFill(['deposit_settled' => true])->save();
    }

    /**
     * +1 strike na performer, sob o lock do perfil (na transação da reserva). 3
     * strikes → flag de review de admin (needs_review — decisão humana, sem ban
     * automático). Idempotente por reserva (strike_recorded).
     */
    private function recordStrike(CallReservation $locked): void
    {
        if ($locked->strike_recorded) {
            return;
        }

        $profile = PerformerProfile::whereKey($locked->performer_profile_id)->lockForUpdate()->first();
        if ($profile === null) {
            return;
        }

        $newCount = (int) $profile->noshow_strike_count + 1;
        $profile->forceFill(['noshow_strike_count' => $newCount])->save();
        $locked->forceFill(['strike_recorded' => true])->save();

        // Trilha auditável do strike: subject = a PERFORMER, metadata só o contador.
        // ZERO linkagem de membro (nada de member_id/reservation_id) — audit_logs é a
        // tabela preservada com IP em claro; não pode carregar o mapa que o Hard
        // Delete apaga (mesma disciplina de story.published/member_photo.*).
        Audit::log('reservation.performer_noshow_strike', $profile, ['strikes' => $newCount]);

        if ($newCount >= (int) config('scheduled_call.strike_review_threshold')) {
            // Reusa a fila de review humano do Sprint 13/payout — o dashboard admin
            // lista performers acima do limiar (sem PII de membro). Ban é decisão
            // humana, não automática.
            Log::warning('reservation.noshow_strike_review', [
                'performer_profile_id' => $profile->id,
                'strikes' => $newCount,
            ]);
        }
    }

    // ── Internos: disponibilidade / janelas / tokens ──────────────────────────────

    /** Slot-padrão da performer, ou o default de config (spec: default 15min). */
    private function slotMinutesFor(PerformerProfile $profile): int
    {
        return (int) ($profile->call_slot_minutes ?? config('scheduled_call.default_slot_minutes'));
    }

    /** Antecedência mínima/máxima para agendar (impede furar as janelas). */
    private function assertWithinBookingWindow(Carbon $scheduledAt): void
    {
        $minLead = (int) config('scheduled_call.min_lead_minutes');
        $maxLeadDays = (int) config('scheduled_call.max_lead_days');

        if ($scheduledAt->lessThan(now()->addMinutes($minLead))
            || $scheduledAt->greaterThan(now()->addDays($maxLeadDays))) {
            throw CallReservationException::invalidTime();
        }
    }

    /**
     * Há colisão do bloco [start, start+slot] com algum bloco ativo (pending/confirmed)
     * da performer, exigido buffer de ambos os lados? Dois blocos precisam de
     * `buffer` de folga: conflito quando NÃO (novo começa depois do fim+buffer do
     * outro) E NÃO (o outro começa depois do fim+buffer do novo).
     */
    private function hasSlotConflict(int $performerProfileId, Carbon $start, int $slotMinutes): bool
    {
        $buffer = (int) config('scheduled_call.buffer_minutes');
        $newStart = $start->copy();
        $newEnd = $start->copy()->addMinutes($slotMinutes);

        // Bound o conjunto no SQL antes de carregar (evita O(n) sob o lock do perfil):
        // um bloco só pode colidir com o novo se começar dentro de [newStart - buffer
        // - maxSlot, newEnd + buffer]. maxSlot = teto do call_slot_minutes (240min).
        $maxSlot = 240;
        $windowStart = $newStart->copy()->subMinutes($buffer + $maxSlot);
        $windowEnd = $newEnd->copy()->addMinutes($buffer);

        return CallReservation::where('performer_profile_id', $performerProfileId)
            ->active()
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->get()
            ->contains(function (CallReservation $other) use ($newStart, $newEnd, $buffer) {
                $otherStart = $other->scheduled_at->copy();
                $otherEnd = $other->scheduled_at->copy()->addMinutes($other->slot_minutes);

                $clear = $newStart->greaterThanOrEqualTo($otherEnd->copy()->addMinutes($buffer))
                    || $otherStart->greaterThanOrEqualTo($newEnd->copy()->addMinutes($buffer));

                return ! $clear;
            });
    }

    /**
     * Teto de duração da chamada agendada: se há PRÓXIMO agendamento na fila, o slot
     * é teto DURO (respeita o buffer e a próxima reserva); senão é flexível — usa o
     * teto geral da performer (call_max_duration_minutes, que pode ser null=ilimitado).
     */
    private function maxDurationForCall(CallReservation $reservation): ?int
    {
        if ($this->hasNextAppointment($reservation)) {
            return (int) $reservation->slot_minutes;
        }

        return $reservation->performerProfile?->call_max_duration_minutes;
    }

    /** Há outro agendamento ativo da performer logo após este (slot + buffer)? */
    private function hasNextAppointment(CallReservation $reservation): bool
    {
        $buffer = (int) config('scheduled_call.buffer_minutes');
        $windowEnd = $reservation->scheduled_at->copy()->addMinutes($reservation->slot_minutes + 2 * $buffer);

        return CallReservation::where('performer_profile_id', $reservation->performer_profile_id)
            ->whereIn('status', [CallReservation::STATUS_PENDING, CallReservation::STATUS_CONFIRMED])
            ->whereKeyNot($reservation->id)
            ->where('scheduled_at', '>', $reservation->scheduled_at)
            ->where('scheduled_at', '<=', $windowEnd)
            ->exists();
    }

    /** Trava a reserva e confere que é da performer (404 uniforme, anti-oráculo). */
    private function lockOwnedByPerformer(int $reservationId, User $performerUser): CallReservation
    {
        $profile = $performerUser->performerProfile;
        $locked = CallReservation::whereKey($reservationId)->lockForUpdate()->first();
        if ($locked === null || $profile === null || $locked->performer_profile_id !== $profile->id) {
            throw CallReservationException::notFound();
        }

        return $locked;
    }

    /** @return array{token:string, wsUrl:string} */
    private function tokenBundle(string $identity, string $role, string $room): array
    {
        return [
            // Chamada 1:1 bidirecional (os dois publicam/assinam vídeo), sem data
            // channel (nada de chat P2P não moderado) — igual ao CallService.
            'token' => $this->livekit->generateToken($identity, $role, $room, [
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => false,
            ]),
            'wsUrl' => (string) config('livekit.url'),
        ];
    }

    private function safeDeleteRoom(?string $room): void
    {
        if ($room === null) {
            return;
        }
        try {
            $this->livekit->deleteRoom($room);
        } catch (\Throwable) {
            Log::warning('reservation.delete_room_failed');
        }
    }
}
