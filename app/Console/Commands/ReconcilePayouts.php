<?php

namespace App\Console\Commands;

use App\Services\PayoutService;
use Illuminate\Console\Command;

class ReconcilePayouts extends Command
{
    protected $signature = 'payouts:reconcile';

    protected $description = 'Settle in-flight payouts with Asaas (covers missed transfer webhooks and ambiguous createTransfer results)';

    public function handle(PayoutService $payoutService): int
    {
        $payoutService->reconcile();
        $this->info('Payout reconciliation complete.');

        return self::SUCCESS;
    }
}
