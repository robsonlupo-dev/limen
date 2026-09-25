<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa o INDICADOR que alguém se cadastrou com o código dele (feat/referral-program,
 * §7.1). Só props escalares — nenhum dado real da pessoa indicada além do rótulo
 * seguro (stage_name/apelido/genérico), montado pelo ReferralService (§7.3).
 */
class ReferralSignupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $referrerName,
        public string $referredLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sua indicação se cadastrou no Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.referral.signup');
    }
}
