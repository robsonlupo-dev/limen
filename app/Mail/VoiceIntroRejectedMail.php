<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A intro de voz da performer foi RECUSADA pela moderação (feat/voice-intro-polish).
 * A recusa é de CONTEÚDO — ancorada nos Termos/Contrato — e distinta da falha
 * técnica (VoiceIntroFailedMail). Mostra o motivo do moderador e convida a regravar.
 * Espelha o par KYC (KycRejectedMail).
 */
class VoiceIntroRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sua apresentação de voz não foi aprovada — Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.voice.rejected');
    }
}
