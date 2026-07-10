<?php

namespace App\Http\Controllers\Web;

use App\Enums\WaitlistTier;
use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistStats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class FounderPanelController extends Controller
{
    public function __construct(private readonly WaitlistStats $stats) {}

    /**
     * Public founder panel: /f/{invite_code}. No auth — this is the shareable
     * viral surface. Shows the person their standing, tier progress, invite link
     * and referral list (masked names only, never emails).
     */
    public function show(string $invite_code): View
    {
        $entry = WaitlistEntry::findByInviteCode($invite_code);

        abort_if($entry === null, 404);

        $tier = $entry->tier;
        $next = $tier->next();
        $count = $entry->referral_count;

        return view('waitlist.founder', [
            'firstName' => Str::of($entry->name)->trim()->explode(' ')->first(),
            'inviteCode' => $entry->invite_code,
            'inviteUrl' => route('convite.show', ['invite_code' => $entry->invite_code]),
            'position' => $this->stats->position($entry),
            'total' => $this->stats->total(),
            'confirmed' => $entry->isConfirmed(),
            'referralCount' => $count,
            'tierLabel' => $tier->label(),
            'tierBenefit' => $tier->benefit(),
            'nextTier' => $next ? [
                'label' => $next->label(),
                'benefit' => $next->benefit(),
                'threshold' => $next->threshold(),
                'remaining' => max(0, $next->threshold() - $count),
                'progress' => $this->progressToNext($tier, $next, $count),
            ] : null,
            'benefits' => $this->benefitLadder($count),
            'recentReferrals' => $this->stats->recentReferrals($entry),
        ]);
    }

    /** Percent (0-100) of the way from the current tier to the next. */
    private function progressToNext(WaitlistTier $tier, WaitlistTier $next, int $count): int
    {
        $span = $next->threshold() - $tier->threshold();

        if ($span <= 0) {
            return 100;
        }

        return (int) min(100, max(0, round(($count - $tier->threshold()) / $span * 100)));
    }

    /**
     * The full tier ladder with an achieved flag, for the "benefits conquered vs
     * next" section.
     *
     * @return array<int, array{label: string, benefit: string, threshold: int, achieved: bool}>
     */
    private function benefitLadder(int $count): array
    {
        return array_map(fn (WaitlistTier $t) => [
            'label' => $t->label(),
            'benefit' => $t->benefit(),
            'threshold' => $t->threshold(),
            'achieved' => $count >= $t->threshold(),
        ], array_slice(WaitlistTier::ordered(), 1)); // skip Curious (baseline)
    }
}
