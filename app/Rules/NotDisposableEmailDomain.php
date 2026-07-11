<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rejects emails from known disposable/throwaway domains (config/waitlist.php).
 * Anti-fraud: throwaway inboxes are the cheap way to farm referral credit by
 * self-confirming invites. Assumes the value already passed the `email` rule, so
 * a host is present; if not, we defer to the other rules and pass.
 */
class NotDisposableEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = Str::of((string) $value)->after('@')->lower()->trim()->value();

        if ($host === '') {
            return;
        }

        foreach (config('waitlist.disposable_email_domains', []) as $domain) {
            // Block the domain itself and any subdomain of it (sub.mailinator.com),
            // a common way to dodge an exact-match list.
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $fail('Use um e-mail pessoal válido — endereços descartáveis não são aceitos.');

                return;
            }
        }
    }
}
