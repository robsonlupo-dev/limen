<?php

namespace App\Services\Waitlist;

use App\Enums\MemberTier;
use App\Enums\PerformerTier;
use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;

/**
 * Computes referral metrics for an entry and derives its role-specific tier.
 * The metrics are the single input to MemberTier/PerformerTier; keeping the
 * counting here (not in the enums) lets the enums stay pure value objects.
 */
class TierCalculator
{
    /**
     * @return array{
     *   confirmed_same:int, confirmed_cross:int,
     *   converted_same:int, converted_cross:int,
     *   active_same:int, active_cross:int
     * }
     */
    public function metricsFor(WaitlistEntry $entry): array
    {
        $base = WaitlistReferral::where('referrer_id', $entry->id);

        return [
            'confirmed_same' => (clone $base)->where('referral_type', 'same_role')->where('confirmed', true)->count(),
            'confirmed_cross' => (clone $base)->where('referral_type', 'cross_role')->where('confirmed', true)->count(),
            'converted_same' => (clone $base)->where('referral_type', 'same_role')->whereNotNull('converted_at')->count(),
            'converted_cross' => (clone $base)->where('referral_type', 'cross_role')->whereNotNull('converted_at')->count(),
            // "Active" = referred users with 2+ purchases. There is no purchase
            // data pre-launch, so these are 0 until wired to the ledger post-launch
            // (only the top tiers — member Architect — depend on them).
            'active_same' => 0,
            'active_cross' => 0,
        ];
    }

    public function tierFor(WaitlistEntry $entry): MemberTier|PerformerTier
    {
        $metrics = $this->metricsFor($entry);

        return $entry->role === 'performer'
            ? PerformerTier::forMetrics($metrics)
            : MemberTier::forMetrics($metrics);
    }

    /**
     * Recompute the cached referral_count and the role tier from the referral
     * edges, and persist. Called by the referral observer whenever an edge
     * changes, so the two never drift.
     */
    public function apply(WaitlistEntry $entry): void
    {
        $metrics = $this->metricsFor($entry);
        $count = $metrics['confirmed_same'] + $metrics['confirmed_cross'];

        if ($entry->role === 'performer') {
            $tier = PerformerTier::forMetrics($metrics);
            $changed = $entry->referral_count !== $count || $entry->tier_performer !== $tier;
            $entry->tier_performer = $tier;
            $entry->tier_member = null;
        } else {
            $tier = MemberTier::forMetrics($metrics);
            $changed = $entry->referral_count !== $count || $entry->tier_member !== $tier;
            $entry->tier_member = $tier;
            $entry->tier_performer = null;
        }

        $entry->referral_count = $count;

        if ($changed) {
            $entry->save();
        }
    }
}
