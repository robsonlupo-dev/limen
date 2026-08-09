<?php

namespace App\Console\Commands;

use App\Services\CallReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Motor de tempo do agendamento de chamada (feat/scheduled-call-v1). Roda a CADA
 * MINUTO e faz quatro varreduras, todas idempotentes por reserva (deposit_settled /
 * *_sent_at) e com os broadcasts em DB::afterCommit:
 *
 *  a) PENDING sem confirmação até T-2h → cancela + refund (silêncio = devolução).
 *  b) CONFIRMED cujo horário chegou:
 *     - performer não entrou até scheduled_at + 2min → no-show performer (refund +
 *       strike); 3 strikes → review de admin.
 *     - performer entrou mas o membro não entrou na janela → no-show membro
 *       (depósito 100% da performer).
 *  c) aviso T-5min aos dois lados (uma vez).
 *  d) aviso T-3min do fim do slot à performer, quando há próximo agendamento.
 *
 * A cobrança dos minutos 2+ da chamada em curso NÃO é aqui — é o `calls:reap-stale`
 * do PR #140 sobre a call_session ligada (pull por heartbeat/refresh do membro).
 */
class ProcessCallReservations extends Command
{
    protected $signature = 'reservations:process';

    protected $description = 'Processa agendamentos de chamada: confirmação vencida, no-shows, avisos.';

    public function handle(CallReservationService $reservations): int
    {
        $expired = $reservations->expireUnconfirmed();
        $noShows = $reservations->resolveNoShows();
        $reminders = $reservations->sendReminders();
        $slotWarnings = $reservations->sendSlotWarnings();

        $this->info("expired={$expired} no_shows={$noShows} reminders={$reminders} slot_warnings={$slotWarnings}");
        Log::info('reservations:process', [
            'expired' => $expired,
            'no_shows' => $noShows,
            'reminders' => $reminders,
            'slot_warnings' => $slotWarnings,
        ]);

        return self::SUCCESS;
    }
}
