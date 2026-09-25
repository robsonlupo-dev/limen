<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessReferralHolds extends Command
{
    protected $signature = 'referral:process-holds';

    protected $description = 'Programa de indicação: detecta a conversão da performer e credita as indicações cujo hold venceu';

    public function handle(ReferralService $referrals): int
    {
        $result = $referrals->processHolds();

        $this->info(sprintf(
            'qualified=%d rewarded=%d clawed_back=%d deferred=%d',
            $result['qualified'],
            $result['rewarded'],
            $result['clawed_back'],
            $result['deferred'],
        ));

        Log::info('referral:process-holds', $result);

        return self::SUCCESS;
    }
}
