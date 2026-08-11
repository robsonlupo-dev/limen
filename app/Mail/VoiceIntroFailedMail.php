<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O processamento (ffmpeg) da intro de voz FALHOU (feat/voice-intro-polish). É um
 * problema TÉCNICO, não uma recusa de conteúdo — a mensagem é deliberadamente
 * distinta da VoiceIntroRejectedMail e não cita os Termos: só convida a reenviar.
 */
class VoiceIntroFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Não conseguimos processar sua apresentação de voz — Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.voice.failed');
    }
}
