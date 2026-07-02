<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    /**
     * Build the PT-BR verification mail. The verification URL points to the
     * web route `verification.verify` (see routes/web.php), which fulfils the
     * request and redirects to the catalog — never the JSON API route.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirme seu e-mail — Limen')
            ->markdown('emails.verify-email', ['verificationUrl' => $url]);
    }
}
