<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Encerra (aqui e no Asaas) as assinaturas canceladas cujo período pago já terminou';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $result = $subscriptionService->expireCanceled();

        $this->info(sprintf('expired=%d failed=%d', $result['expired'], $result['failed']));

        // Rastro operacional: falha aqui significa assinatura ainda cobrando no
        // gateway depois de o membro ter cancelado.
        Log::info('subscriptions:expire', $result);

        return self::SUCCESS;
    }
}
