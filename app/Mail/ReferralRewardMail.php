<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa um dos lados que o bônus de indicação foi creditado (feat/referral-program,
 * §7.2). `role` = 'referrer' (ganhou por indicar) ou 'referred' (ganhou de
 * boas-vindas). `counterpartyLabel` só é usado no e-mail do indicador, e é o rótulo
 * seguro da pessoa indicada (nunca nome real/e-mail/CPF — §7.3).
 */
class ReferralRewardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $beneficiaryName,
        public string $role,
        public int $amount,
        public string $counterpartyLabel = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Você ganhou tokens de bônus no Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.referral.reward');
    }
}
