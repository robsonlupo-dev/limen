<?php

namespace App\Models;

use App\Enums\WaitlistTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class WaitlistEntry extends Model
{
    // Only user-supplied fields are mass-assignable. The program-controlled
    // fields (invite_code, invite_token, referred_by, confirmed_at,
    // referral_count, tier) are set by direct assignment in WaitlistService /
    // the observers — never from request input — so a stray create($request->all())
    // can never forge a tier or referral count.
    protected $fillable = ['name', 'email', 'role', 'world', 'source', 'age_confirmed'];

    protected $casts = [
        'age_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'referral_count' => 'integer',
        'tier' => WaitlistTier::class,
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

    /**
     * A unique invite code in the form LIMEN-XXX-0000: three letters from the
     * name (padded, uppercased) plus four random digits. Loops until unique so
     * the code never collides. Generated once at signup and never changes.
     */
    public static function generateInviteCode(string $name): string
    {
        $letters = Str::upper(str_pad(
            substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'LMN', 0, 3),
            3,
            'X',
        ));

        do {
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
