<?php

namespace App\Services\Waitlist;

use App\Mail\WaitlistNurtureMail;
use App\Models\WaitlistEmailLog;
use App\Models\WaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Drives the Founding Members nurturing drip. For each configured step it finds
 * CONFIRMED entries whose confirmed_at is old enough and that have not yet been
 * sent that step, then dispatches the email exactly once.
 *
 * Idempotency (mirroring the payment-webhook discipline): we claim-then-send.
 * insertOrIgnore against the unique (waitlist_entry_id, email_key) index is the
 * atomic claim — it inserts one row or, if the step was already logged (even by
 * a concurrent run), inserts nothing. We only queue the mail when the claim
 * actually inserted, so re-running the command never double-sends.
 *
 * Unconfirmed entries are excluded (no double opt-in → no drip), and unsubscribe
 * deletes the entry, which cascade-deletes its log and drops it from selection.
 */
class WaitlistNurtureService
{
    public function dispatchDue(): int
    {
        $sent = 0;

        foreach (config('waitlist.nurture', []) as $step) {
            if (! ($step['enabled'] ?? false)) {
                continue;
            }

            $sent += $this->dispatchStep($step['key'], (int) $step['after_days']);
        }

        return $sent;
    }

    private function dispatchStep(string $key, int $afterDays): int
    {
        $cutoff = Carbon::now()->subDays($afterDays);
        $startAt = config('waitlist.nurture_start_at');
        $maxPerRun = config('waitlist.nurture_max_per_run');
        $sent = 0;

        $query = WaitlistEntry::query()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $cutoff)
            // Backfill guard: never enroll entries confirmed before the launch floor.
            ->when($startAt, fn ($q) => $q->where('confirmed_at', '>=', Carbon::parse($startAt)))
            ->whereDoesntHave('emailLog', fn ($q) => $q->where('email_key', $key));

        // A capped run fetches at most $maxPerRun due entries (the throttle); an
        // uncapped run streams every due entry in chunks. Either way each entry is
        // claimed-then-sent, so the leftover of a capped run goes out next hour.
        if ($maxPerRun !== null) {
            foreach ($query->orderBy('id')->limit((int) $maxPerRun)->get() as $entry) {
                $sent += $this->claimAndSend($entry, $key);
            }
        } else {
            $query->chunkById(200, function ($entries) use ($key, &$sent) {
                foreach ($entries as $entry) {
                    $sent += $this->claimAndSend($entry, $key);
                }
            });
        }

        return $sent;
    }

    /** Atomic claim (insertOrIgnore) then queue the mail. Returns 1 if sent, 0 if already claimed. */
    private function claimAndSend(WaitlistEntry $entry, string $key): int
    {
        $claimed = WaitlistEmailLog::insertOrIgnore([
            'waitlist_entry_id' => $entry->id,
            'email_key' => $key,
            'sent_at' => Carbon::now(),
        ]);

        if ($claimed !== 1) {
            return 0;
        }

        Mail::to($entry->email)->send(new WaitlistNurtureMail($entry, $key));

        return 1;
    }
}
