<?php

namespace App\Models;

use App\Enums\MemberTier;
use App\Enums\PerformerTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WaitlistEntry extends Model
{
    // Only user-supplied fields are mass-assignable. The program-controlled
    // fields (invite_code, invite_token, referred_by, confirmed_at,
    // position_in_role, referral_count, tier_member, tier_performer) are set by
    // direct assignment in WaitlistService / the observers — never from request
    // input — so a stray create($request->all()) can never forge a tier.
    protected $fillable = ['name', 'email', 'role', 'world', 'world_preferences', 'performer_kind', 'source', 'age_confirmed'];

    protected $casts = [
        'age_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'referral_count' => 'integer',
        'position_in_role' => 'integer',
        'world_preferences' => 'array',
        'tier_member' => MemberTier::class,
        'tier_performer' => PerformerTier::class,
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /** The entry that referred this one, if any. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /** The single referral edge where this entry is the referred person. */
    public function referralEdge(): HasOne
    {
        return $this->hasOne(WaitlistReferral::class, 'referred_id');
    }

    /** All referral edges where this entry is the referrer. */
    public function referralsMade(): HasMany
    {
        return $this->hasMany(WaitlistReferral::class, 'referrer_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isPerformer(): bool
    {
        return $this->role === 'performer';
    }

    /** The tier that applies to this entry's role (base tier if none set yet). */
    public function activeTier(): MemberTier|PerformerTier
    {
        return $this->isPerformer()
            ? ($this->tier_performer ?? PerformerTier::Candidate)
            : ($this->tier_member ?? MemberTier::Curious);
    }

    public function tierLabel(): string
    {
        return $this->activeTier()->label();
    }

    /** "Membro Fundador" / "Performer Fundadora" — the founder title by role. */
    public function founderTitle(): string
    {
        return $this->isPerformer() ? 'Performer Fundadora' : 'Membro Fundador';
    }

    /**
     * A unique invite code in the form LIMEN-XXX-0000: three random letters plus
     * four random digits (~1.7e8 combinations). The letters are random — not
     * derived from the name — on purpose: a name-derived prefix would let anyone
     * enumerate a target's public founder panel (an "is X on Limen?" oracle) by
     * brute-forcing only the 4 digits. Generated once at signup, never changes.
     */
    public static function generateInviteCode(): string
    {
        do {
            $letters = '';
            for ($i = 0; $i < 3; $i++) {
                $letters .= chr(random_int(65, 90)); // A–Z
            }
            $code = sprintf('LIMEN-%s-%04d', $letters, random_int(0, 9999));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    /** A high-entropy, unguessable token for the confirm/unsubscribe links. */
    public static function generateInviteToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function findByInviteToken(?string $token): ?self
    {
        return $token ? static::where('invite_token', $token)->first() : null;
    }

    public static function findByInviteCode(?string $code): ?self
    {
        return $code ? static::where('invite_code', $code)->first() : null;
    }
}
