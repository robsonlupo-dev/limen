<?php

namespace App\Services\Waitlist;

use App\Models\WaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WaitlistStats
{
    /** Total signups for the same role as the given entry. */
    public function totalInRole(WaitlistEntry $entry): int
    {
        return WaitlistEntry::where('role', $entry->role)->count();
    }

    /**
     * The last few people this entry referred, most recent first, exposed with
     * only a masked first name/initial — never the email (privacy on a public
     * panel). Confirmed referrals are highlighted.
     *
     * @return Collection<int, array{name: string, confirmed: bool}>
     */
    public function recentReferrals(WaitlistEntry $entry, int $limit = 8): Collection
    {
        return $entry->referralsMade()
            ->with('referred:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($edge) => [
                'name' => $this->maskName($edge->referred?->name),
                'confirmed' => (bool) $edge->confirmed,
            ]);
    }

    /** "Rafael" -> "Rafael", "Rafael Souza" -> "Rafael S." — no full surnames. */
    private function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? 'Alguém';

        if (isset($parts[1]) && $parts[1] !== '') {
            return $first.' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.';
        }

        return $first;
    }

    /**
     * Aggregate numbers for the admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function adminSummary(): array
    {
        $total = WaitlistEntry::count();
        $confirmed = WaitlistEntry::whereNotNull('confirmed_at')->count();
        $confirmedReferrals = WaitlistEntry::whereNotNull('referred_by')
            ->whereNotNull('confirmed_at')->count();

        return [
            'total' => $total,
            'confirmed' => $confirmed,
            'by_role' => [
                'member' => WaitlistEntry::where('role', 'member')->count(),
                'performer' => WaitlistEntry::where('role', 'performer')->count(),
            ],
            'confirmation_rate' => $total > 0 ? round($confirmed / $total * 100, 1) : 0.0,
            // Viral coefficient K: confirmed referrals generated per confirmed
            // member. K >= 1 means self-sustaining growth.
            'viral_coefficient' => $confirmed > 0 ? round($confirmedReferrals / $confirmed, 2) : 0.0,
            'top_referrers' => WaitlistEntry::where('referral_count', '>', 0)
                ->orderByDesc('referral_count')
                ->limit(10)
                ->get(['name', 'invite_code', 'referral_count', 'role', 'tier_member', 'tier_performer'])
                ->map(fn ($e) => [
                    'name' => $e->name,
                    'invite_code' => $e->invite_code,
                    'referral_count' => $e->referral_count,
                    'tier' => $e->tierLabel(),
                ]),
            'daily_growth' => $this->dailyGrowth(),
        ];
    }

    /** Signups per day for the last 14 days, oldest first. */
    private function dailyGrowth(): Collection
    {
        $since = Carbon::today()->subDays(13);

        $counts = WaitlistEntry::where('created_at', '>=', $since)
            ->get(['created_at'])
            ->groupBy(fn ($e) => $e->created_at->toDateString())
            ->map->count();

        return collect(range(0, 13))->map(function ($offset) use ($since, $counts) {
            $day = $since->copy()->addDays($offset)->toDateString();

            return ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        });
    }
}
