<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GrantSubscriptionTokens extends Command
{
    protected $signature = 'subscriptions:grant-monthly';

    protected $description = 'Reconcilia a franquia mensal dos Círculos: concede o ciclo que ficou sem marca (M.13.4/M.13.8)';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $result = $subscriptionService->grantDueFranchises();

        $this->info(sprintf(
            'granted=%d pended=%d failed=%d',
            $result['granted'],
            $result['pended'],
            $result['failed'],
        ));

        // Rastro operacional: em regime normal o webhook já concedeu tudo e este
        // command é no-op (granted=0). granted>0 significa um ciclo que rodou sem
        // o webhook conceder — vale investigar a entrega do webhook do Asaas.
        Log::info('subscriptions:grant-monthly', $result);

        return self::SUCCESS;
    }
}
