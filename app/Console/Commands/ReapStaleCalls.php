<?php

namespace App\Console\Commands;

use App\Services\CallService;
use App\Services\GroupShowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * GC das chamadas/participações ABANDONADAS (Sprint 15). A cobrança de vídeo é
 * PULL (só o heartbeat/refresh do cliente do membro cobra e mantém o estado). Um
 * cliente que fecha a aba sem `end`/`leave` deixa a sessão 1:1 ou a participação
 * group `active` para sempre — e `CallService::memberIsBusy()` a lê como "ocupado",
 * travando o membro (e, no 1:1, também a performer) de iniciar nova chamada.
 *
 * Este reaper encerra as linhas cujo tempo PAGO (`minutes_billed` minutos desde o
 * início) venceu há mais que a folga sem novo minuto — sinal de cliente sumido.
 * Um cliente saudável renova `minutes_billed` a cada minuto e nunca é alcançado.
 * Não cobra nada (o LiveKit já ejetou o sumido no fim do token_ttl); só faxina o
 * estado. Precedente `calls:expire-pending`/`stories:purge`.
 */
class ReapStaleCalls extends Command
{
    protected $signature = 'calls:reap-stale';

    protected $description = 'Encerra chamadas 1:1 e participações de group show abandonadas (sem heartbeat).';

    public function handle(CallService $calls, GroupShowService $group): int
    {
        $sessions = $calls->reapStaleSessions();
        $participants = $group->expireStaleParticipants();

        $this->info("sessions={$sessions} participants={$participants}");
        Log::info('calls:reap-stale', ['sessions' => $sessions, 'participants' => $participants]);

        return self::SUCCESS;
    }
}
