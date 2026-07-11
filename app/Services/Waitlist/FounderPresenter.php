<?php

namespace App\Services\Waitlist;

use App\Models\WaitlistEntry;
use Illuminate\Support\Str;

/**
 * Builds the role-aware view model shared by the confirmation email and the
 * public founder panel, so both show identical position/tier/next-reward data.
 */
class FounderPresenter
{
    public function __construct(
        private readonly TierCalculator $tiers,
        private readonly WaitlistStats $stats,
    ) {}

    /** @return array<string, mixed> */
    public function for(WaitlistEntry $entry): array
    {
        $metrics = $this->tiers->metricsFor($entry);
        $tier = $entry->activeTier();
        $next = $tier->next();

        return [
            'firstName' => Str::of($entry->name)->trim()->explode(' ')->first(),
            'founderTitle' => $entry->founderTitle(),
            'isPerformer' => $entry->isPerformer(),
            'position' => (int) ($entry->position_in_role ?? $this->stats->totalInRole($entry)),
            'totalInRole' => $this->stats->totalInRole($entry),
            'confirmed' => $entry->isConfirmed(),
            'referralCount' => (int) $entry->referral_count,
            'tierLabel' => $tier->label(),
            'tierBenefit' => $tier->benefit(),
            'nextTier' => $next ? $this->nextTier($next, $metrics) : null,
            'benefits' => $this->benefitLadder($entry, $metrics),
            'recentReferrals' => $this->stats->recentReferrals($entry),
            'inviteCode' => $entry->invite_code,
            'inviteUrl' => route('convite.show', ['invite_code' => $entry->invite_code]),
        ];
    }

    /** @return array{label:string, benefit:string, remaining:int, phrase:string, progress:int} */
    private function nextTier(object $next, array $metrics): array
    {
        $req = $next->requirement();
        $have = (int) ($metrics[$req['metric']] ?? 0);
        $threshold = (int) $req['threshold'];

        return [
            'label' => $next->label(),
            'benefit' => $next->benefit(),
            'remaining' => max(0, $threshold - $have),
            'phrase' => $next->requirementPhrase(),
            'progress' => $threshold > 0 ? (int) min(100, floor($have / $threshold * 100)) : 100,
        ];
    }

    /**
     * The role's tier ladder (excluding the base tier) with an achieved flag.
     * Achieved is evaluated per-tier against its own requirement — because tiers
     * key off different metrics, a higher tier can be reached before a lower one.
     *
     * @return array<int, array{label:string, benefit:string, threshold:int, achieved:bool}>
     */
    private function benefitLadder(WaitlistEntry $entry, array $metrics): array
    {
        $cases = $entry->isPerformer()
            ? \App\Enums\PerformerTier::ordered()
            : \App\Enums\MemberTier::ordered();

        return array_map(function ($tier) use ($metrics) {
            $req = $tier->requirement();

            return [
                'label' => $tier->label(),
                'benefit' => $tier->benefit(),
                'threshold' => (int) $req['threshold'],
                'achieved' => (int) ($metrics[$req['metric']] ?? 0) >= (int) $req['threshold'],
            ];
        }, array_slice($cases, 1)); // drop the base tier
    }
}
