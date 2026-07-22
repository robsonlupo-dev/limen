<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Reconcile pending payments with Asaas (covers missed webhooks, expires overdue)';

    public function handle(PaymentService $paymentService): int
    {
        $paymentService->reconcile();
        $this->info('Payment reconciliation complete.');

        return self::SUCCESS;
    }
}
