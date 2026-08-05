<?php

namespace App\Support;

use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use App\Services\TokenService;

/**
 * Motor ÚNICO de cobrança pré-paga por minuto (Sprint 15). Usado pela chamada 1:1
 * (CallService::reconcileBilling) E pelo group show (GroupShowService::reconcile*)
 * — UMA implementação, dois chamadores, para a idempotência-por-minuto e o "saldo
 * nunca negativo" não divergirem (o risco de duas implementações que a revisão de
 * código do #140 tratou como crítico).
 *
 * PRESSUPÕE que a linha do "billable" (call_session 1:1 ou call_session_participant
 * do group) já está TRAVADA (lockForUpdate) na transação do chamador — este motor
 * não trava nada: só decide QUANTOS minutos cobrar e chama o `$charge`. O chamador
 * persiste o `minutes_billed` retornado e decide o status.
 *
 * Pré-pago: para estar conectado no segundo `t`, é preciso já ter pago o minuto
 * `floor(t/60)+1`. Cobra enquanto `minutes_billed < required` E `saldo >= price` E
 * (sem teto ou abaixo dele). `charge()` só é chamado com `saldo >= price` e o
 * `TokenService::debit` re-checa sob o lock do wallet — saldo < 0 é impossível.
 */
class MinuteBiller
{
    public function __construct(private TokenService $tokens) {}

    /**
     * @param  callable():void  $charge  cobra UM minuto (débito do membro + crédito
     *                                    da performer); lança InsufficientBalanceException
     *                                    se o wallet recusar sob lock (corrida com
     *                                    outro débito — tip/gift), tratada como "não cabe".
     * @return array{minutes_billed:int, balance:int, minutes_left:int, can_continue:bool, ended_reason:?string}
     */
    public function reconcile(
        User $member,
        int $price,
        int $minutesBilled,
        int $startedAtTs,
        ?int $maxMinutes,
        callable $charge,
    ): array {
        $elapsed = now()->getTimestamp() - $startedAtTs;
        $required = intdiv(max(0, $elapsed), 60) + 1;

        $endedReason = null;

        while ($minutesBilled < $required) {
            if ($maxMinutes !== null && $minutesBilled >= $maxMinutes) {
                $endedReason = 'max_duration';
                break;
            }
            if ($this->tokens->balance($member) < $price) {
                $endedReason = 'insufficient_balance';
                break;
            }
            try {
                $charge();
            } catch (InsufficientBalanceException) {
                $endedReason = 'insufficient_balance';
                break;
            }
            $minutesBilled++;
        }

        $balance = $this->tokens->balance($member);
        $canContinue = $minutesBilled >= $required && $endedReason === null;

        return [
            'minutes_billed' => $minutesBilled,
            'balance' => $balance,
            'minutes_left' => $price > 0 ? intdiv($balance, $price) : 0,
            'can_continue' => $canContinue,
            'ended_reason' => $canContinue ? null : ($endedReason ?? 'insufficient_balance'),
        ];
    }
}
