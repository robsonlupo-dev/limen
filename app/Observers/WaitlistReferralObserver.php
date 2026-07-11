<?php

namespace App\Observers;

use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;
use App\Services\Waitlist\TierCalculator;

class WaitlistReferralObserver
{
    public function __construct(private readonly TierCalculator $tiers) {}

    public function saved(WaitlistReferral $referral): void
    {
        $this->recompute($referral->referrer_id);
    }

    public function deleted(WaitlistReferral $referral): void
    {
        $this->recompute($referral->referrer_id);
    }

    /**
     * Recompute the referrer's cached referral_count and role tier from the
     * referral edges (the source of truth). Recomputing — rather than
     * incrementing — keeps the cache correct under retries, out-of-order
     * confirmations, conversions and deletes.
     */
    private function recompute(?int $referrerId): void
    {
        if ($referrerId === null) {
            return;
        }

        $referrer = WaitlistEntry::find($referrerId);

        if ($referrer !== null) {
            $this->tiers->apply($referrer);
        }
    }
}
