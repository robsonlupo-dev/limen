<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Uma foto da galeria de perfil do membro foi RECUSADA pela moderação
 * (feat/member-gallery-and-profile). Mostra o motivo do moderador e convida a
 * reenviar. Espelha VoiceIntroRejectedMail.
 */
class MemberPhotoRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sua foto de perfil não foi aprovada — Limen');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.member.photo-rejected');
    }
}
