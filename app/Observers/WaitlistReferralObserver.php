<?php

namespace App\Observers;

use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;

class WaitlistReferralObserver
{
    public function saved(WaitlistReferral $referral): void
    {
        $this->syncReferrerCount($referral->referrer_id);
    }

    public function deleted(WaitlistReferral $referral): void
    {
        $this->syncReferrerCount($referral->referrer_id);
    }

    /**
     * Recompute the referrer's cached referral_count from the source of truth
     * (confirmed rows in waitlist_referrals) and persist it. Saving the referrer
     * triggers WaitlistEntryObserver, which re-derives the tier. Recomputing
     * (rather than incrementing) keeps the cache correct even under retries or
     * out-of-order confirmations.
     */
    private function syncReferrerCount(?int $referrerId): void
    {
        if ($referrerId === null) {
            return;
        }

        $referrer = WaitlistEntry::find($referrerId);

        if ($referrer === null) {
            return;
        }

        $confirmed = WaitlistReferral::where('referrer_id', $referrerId)
            ->where('confirmed', true)
            ->count();

        if ($referrer->referral_count !== $confirmed) {
            $referrer->referral_count = $confirmed;
            $referrer->save();
        }
    }
}
