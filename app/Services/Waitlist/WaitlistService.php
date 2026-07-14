<?php

namespace App\Services\Waitlist;

use App\Enums\MemberTier;
use App\Enums\PerformerTier;
use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    /** Max referrals that may be attributed to a single signup IP per 24h. */
    private const MAX_REFERRALS_PER_IP_24H = 3;

    /**
     * Idempotent per (email, role) join. On a genuinely new entry we mint the
     * immutable invite code + token, freeze the per-role position and seed the
     * base tier, and — when the signup came through a valid invite link — record
     * the referral edge (subject to self-referral and anti-fraud guards).
     *
     * @return array{entry: WaitlistEntry, created: bool}
     */
    public function join(array $data, ?WaitlistEntry $referrer, ?string $ip): array
    {
        $entry = WaitlistEntry::firstOrNew([
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        $created = ! $entry->exists;

        $entry->name = $data['name'];
        // World capture is role-specific: a performer picks the single world they
        // represent (+ solo/casal); a member picks their private world preferences.
        // The other role's fields are nulled so no stale cross-role data lingers.
        $isPerformer = $data['role'] === 'performer';
        $entry->world = $isPerformer ? ($data['world'] ?? null) : null;
        $entry->performer_kind = $isPerformer ? ($data['performer_kind'] ?? null) : null;
        $entry->world_preferences = $isPerformer ? null : ($data['world_preferences'] ?? null);
        $entry->age_confirmed = true;
        $entry->source = $created ? ($data['source'] ?? 'landing') : $entry->source;

        // A referral is only attributed on a brand-new entry, from a real *other*
        // entry, not a self re-invite, and within the anti-fraud cap.
        $attributeReferral = $created
            && $referrer !== null
            && $referrer->exists
            && ! $this->isSelfReferral($referrer, $data['email'])
            && $this->ipUnderReferralCap($ip);

        DB::transaction(function () use ($entry, $data, $created, $attributeReferral, $referrer, $ip) {
            if ($created) {
                $entry->invite_code = WaitlistEntry::generateInviteCode();
                $entry->invite_token = WaitlistEntry::generateInviteToken();
                // Position counted separately per role, frozen at signup.
                $entry->position_in_role = WaitlistEntry::where('role', $data['role'])->count() + 1;
                $this->seedBaseTier($entry);
            }

            if ($attributeReferral) {
                $entry->referred_by = $referrer->id;
            }

            $entry->save();

            if ($attributeReferral) {
                WaitlistReferral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $entry->id,
                    'confirmed' => false,
                    'referral_type' => $referrer->role === $entry->role ? 'same_role' : 'cross_role',
                    'referred_ip_hash' => $this->hashIp($ip),
                ]);
            }
        });

        return ['entry' => $entry, 'created' => $created];
    }

    /**
     * Confirm a signup's email (double opt-in). Idempotent. Confirming the entry
     * also confirms its referral edge, which the observer turns into tier credit
     * for the referrer.
     */
    public function confirm(WaitlistEntry $entry): void
    {
        if ($entry->isConfirmed()) {
            return;
        }

        DB::transaction(function () use ($entry) {
            $entry->confirmed_at = now();
            $entry->save();

            $edge = $entry->referralEdge()->first();

            if ($edge !== null && ! $edge->confirmed) {
                $edge->confirmed = true;
                $edge->save();
            }
        });
    }

    /**
     * Mark a signup as converted into a real registered user (post-launch). The
     * stronger signal behind the founder/patron/ambassador tiers; idempotent.
     */
    public function convert(WaitlistEntry $entry): void
    {
        $edge = $entry->referralEdge()->first();

        if ($edge !== null && $edge->converted_at === null) {
            $edge->converted_at = now();
            $edge->save();
        }
    }

    private function seedBaseTier(WaitlistEntry $entry): void
    {
        if ($entry->role === 'performer') {
            $entry->tier_performer = PerformerTier::Candidate;
        } else {
            $entry->tier_member = MemberTier::Curious;
        }
    }

    private function isSelfReferral(WaitlistEntry $referrer, string $email): bool
    {
        return strcasecmp($referrer->email, $email) === 0;
    }

    private function ipUnderReferralCap(?string $ip): bool
    {
        // Fail closed: without an IP we can't enforce the cap, so we don't grant
        // referral credit rather than leave an unlimited hole.
        if ($ip === null) {
            return false;
        }

        $recent = WaitlistReferral::where('referred_ip_hash', $this->hashIp($ip))
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $recent < self::MAX_REFERRALS_PER_IP_24H;
    }

    /** One-way keyed hash so the raw IP never lands in the database. */
    private function hashIp(?string $ip): ?string
    {
        return $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
