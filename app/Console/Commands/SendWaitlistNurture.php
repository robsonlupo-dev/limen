<?php

namespace App\Console\Commands;

use App\Services\Waitlist\WaitlistNurtureService;
use Illuminate\Console\Command;

class SendWaitlistNurture extends Command
{
    protected $signature = 'waitlist:send-nurture';

    protected $description = 'Dispatch any due Founding Members nurturing emails to confirmed waitlist entries (idempotent)';

    public function handle(WaitlistNurtureService $service): int
    {
        $sent = $service->dispatchDue();
        $this->info("Waitlist nurturing: {$sent} email(s) queued.");

        return self::SUCCESS;
    }
}
