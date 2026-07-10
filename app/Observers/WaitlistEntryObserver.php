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

    /**
     * When an entry is deleted (e.g. unsubscribe), remove the edge where it was
     * the *referred* person through Eloquent, so its referrer's cached
     * count/tier are recomputed by WaitlistReferralObserver. The DB-level
     * cascade alone would bypass the observer and leave the referrer inflated —
     * which would otherwise let someone farm a tier and then delete the
     * referreds to hide it. Edges where this entry is the referrer cascade away
     * safely (that count belonged to this row, which is going away).
     */
    public function deleting(WaitlistEntry $entry): void
    {
        $entry->referralEdge()->first()?->delete();
    }
}
