<?php

namespace App\Console\Commands;

use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMonthlyPayouts extends Command
{
    protected $signature = 'payouts:process-monthly';

    protected $description = 'Sweep mensal do dia 1: paga os ganhos devidos do mês anterior via PIX (M.10/M.13.5)';

    public function handle(PayoutService $payoutService): int
    {
        $result = $payoutService->sweepMonthlyPayouts();

        $this->info(sprintf(
            'created=%d tokens=%d skipped_no_key=%d skipped_below_min=%d skipped_ineligible=%d skipped_duplicate=%d failed=%d',
            $result['created'],
            $result['tokens'],
            $result['skipped_no_key'],
            $result['skipped_below_min'],
            $result['skipped_ineligible'],
            $result['skipped_duplicate'],
            $result['failed'],
        ));

        // Rastro operacional — só contadores, nunca PII (chave PIX/CPF nunca em log).
        Log::info('payouts:process-monthly', $result);

        return self::SUCCESS;
    }
}
