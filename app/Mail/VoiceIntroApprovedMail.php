<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A intro de voz da performer foi APROVADA pela moderação e está no ar
 * (feat/voice-intro-polish). Espelha o par KYC (KycApprovedMail): Mailable
 * simples, enviado por um job ShouldQueue.
 */
class VoiceIntroApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sua apresentação de voz está no ar — Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.voice.approved');
    }
}
