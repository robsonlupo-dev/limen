<?php

namespace App\Observers;

use App\Enums\WaitlistTier;
use App\Models\WaitlistEntry;

class WaitlistEntryObserver
{
    /**
     * Keep the tier derived from referral_count on every write, so the two can
     * never drift. Runs before the row is persisted (no extra query, no
     * recursion). referral_count is a plain integer here (the cast to enum
     * applies to `tier`, not the count).
     */
    public function saving(WaitlistEntry $entry): void
    {
        $entry->tier = WaitlistTier::forCount((int) $entry->referral_count);
    }
}
