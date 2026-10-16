<?php

namespace App\Console\Commands;

use App\Services\Waitlist\WaitlistNurtureService;
use Illuminate\Console\Command;

class SendWaitlistNurture extends Command
{
    protected $signature = 'waitlist:send-nurture';

    protected $description = 'Dispatch due Founding Members nurturing emails (confirmed) and 24h confirmation reminders (unconfirmed) — idempotent';

    public function handle(WaitlistNurtureService $service): int
    {
        $nurtured = $service->dispatchDue();
        $reminded = $service->dispatchConfirmationReminders();
        $this->info("Waitlist: {$nurtured} nurturing + {$reminded} confirmation reminder(s) queued.");

        return self::SUCCESS;
    }
}
