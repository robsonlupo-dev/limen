<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    use Queueable;

    /**
     * Build the PT-BR password reset mail. The URL is produced by the
     * ResetPassword::createUrlUsing callback (see AppServiceProvider), which
     * points at the web route `password.reset`.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Redefinição de senha — Limen')
            ->markdown('emails.reset-password', [
                'resetUrl' => $url,
                'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
