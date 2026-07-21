<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que o pedido de exclusão foi cancelado.
 *
 * Existe pelo lado feio do fluxo: quem tem a sessão do titular pode CANCELAR um
 * pedido legítimo, e sem este e-mail o titular continuaria achando que está
 * saindo da plataforma enquanto a conta segue de pé. É o único sinal fora da
 * sessão de que alguém desfez a decisão dele.
 */
class AccountDeletionCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Exclusão da sua conta foi cancelada — Limen');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account.deletion-cancelled',
            with: ['settingsUrl' => route('consumer.settings')],
        );
    }
}
