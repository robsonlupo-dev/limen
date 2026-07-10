<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WaitlistEntry extends Model
{
    protected $fillable = ['name', 'email', 'role', 'world', 'source', 'age_confirmed'];

    protected $casts = ['age_confirmed' => 'boolean'];

    /**
     * Deterministic, non-forgeable token for one-click unsubscribe links. Keyed
     * on APP_KEY so an attacker cannot craft a link to remove someone else's
     * email; deterministic so the link emailed today still works tomorrow.
     */
    public static function makeUnsubscribeToken(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }

    public function unsubscribeToken(): string
    {
        return static::makeUnsubscribeToken($this->email);
    }

    /** Constant-time comparison to avoid leaking the token via timing. */
    public static function isValidUnsubscribeToken(string $email, string $token): bool
    {
        return hash_equals(static::makeUnsubscribeToken($email), $token);
    }
}
