<?php

namespace App\Console\Commands;

use App\Services\CallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * GC dos pedidos de chamada 1:1 pendentes vencidos (Sprint 15).
 *
 * A expiração já vale na LEITURA (CallService: reconcilePending sob o lock do
 * perfil no request, e o accept/decline relê o status) — isto é só faxina, para
 * um pending sem resposta não ficar "ocupando" contadores/telas. Mesmo precedente
 * de `stories:purge` e `otp:purge`. Marca `status=expired` (não deleta a linha:
 * o histórico do membro sai no Hard Delete, não aqui).
 */
class ExpirePendingCalls extends Command
{
    protected $signature = 'calls:expire-pending';

    protected $description = 'Marca como expirados os pedidos de chamada 1:1 pendentes há mais de 60s.';

    public function handle(CallService $calls): int
    {
        $expired = $calls->expireStalePending();

        $this->info("expired={$expired}");
        Log::info('calls:expire-pending', ['expired' => $expired]);

        return self::SUCCESS;
    }
}
