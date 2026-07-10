<?php

namespace App\Services\Waitlist;

use App\Models\WaitlistEntry;
use App\Models\WaitlistReferral;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    /** Max referrals that may be attributed to a single signup IP per 24h. */
    private const MAX_REFERRALS_PER_IP_24H = 3;

    /**
     * Idempotent per (email, role) join. On a genuinely new entry we mint the
     * immutable invite code + token and, when the signup came through a valid
     * invite link, record the referral edge (subject to the anti-fraud cap).
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
        $entry->world = $data['world'] ?? null;
        $entry->age_confirmed = true;
        $entry->source = $created ? ($data['source'] ?? 'landing') : $entry->source;

        if ($created) {
            $entry->invite_code = WaitlistEntry::generateInviteCode();
            $entry->invite_token = WaitlistEntry::generateInviteToken();
        }

        // A referral is only attributed on a brand-new entry, when the referrer
        // is a real *other* entry, and not the same person re-inviting themselves.
        $attributeReferral = $created
            && $referrer !== null
            && $referrer->exists
            && ! $this->isSelfReferral($referrer, $data['email'])
            && $this->ipUnderReferralCap($ip);

        if ($attributeReferral) {
            $entry->referred_by = $referrer->id;
        }

        DB::transaction(function () use ($entry, $attributeReferral, $ip) {
            $entry->save();

            if ($attributeReferral) {
                WaitlistReferral::create([
                    'referrer_id' => $entry->referred_by,
                    'referred_id' => $entry->id,
                    'confirmed' => false,
                    'referred_ip_hash' => $this->hashIp($ip),
                ]);
            }
        });

        return ['entry' => $entry, 'created' => $created];
    }

    /**
     * Confirm a signup's email (double opt-in). Idempotent: a second call (e.g.
     * from a link pre-fetch) is a no-op. Confirming the entry also confirms its
     * referral edge, which the observer turns into viral credit for the referrer.
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
