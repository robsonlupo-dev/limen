<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class WaitlistEntry extends Model
{
    protected $fillable = ['name', 'email', 'role', 'world', 'source', 'age_confirmed'];

    protected $casts = ['age_confirmed' => 'boolean'];

    /**
     * Opaque, tamper-proof unsubscribe token: the email encrypted with APP_KEY
     * (AES-256 + HMAC via Laravel Crypt). Carrying the email *inside* the token
     * keeps the raw email out of the URL/query string — and therefore out of the
     * nginx access log (CLAUDE.md principle 4: PII never in log, never in URL).
     * Not forgeable without APP_KEY; decryption fails closed on any tampering.
     */
    public static function makeUnsubscribeToken(string $email): string
    {
        return Crypt::encryptString(Str::lower(trim($email)));
    }

    public function unsubscribeToken(): string
    {
        return static::makeUnsubscribeToken($this->email);
    }

    /**
     * The normalized email carried by an unsubscribe token, or null if the token
     * is missing, tampered with, or was signed under a different APP_KEY.
     */
    public static function emailFromUnsubscribeToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        try {
            return Str::lower(trim(Crypt::decryptString($token)));
        } catch (DecryptException) {
            return null;
        }
    }
}
