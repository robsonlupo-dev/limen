<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\OtpCode;

/**
 * E-mail com o código de acesso OTP (Sprint 11).
 *
 * DISCRETO por decisão do PO: assunto e remetente não denunciam a natureza da
 * plataforma. "Seu código de acesso" é neutro; o remetente é a marca "Limen"
 * (que não revela conteúdo adulto) sobre o MAIL_FROM_ADDRESS. O corpo traz o
 * código em destaque e diz que expira em 5 min — nada além disso.
 *
 * **Sem imagem remota** (pixel audit): o layout usa só o tema markdown local do
 * Laravel; nenhum `<img src="http…">` que confirme abertura ou vaze IP. O código
 * viaja no corpo porque é o produto do e-mail — não em audit, não em log.
 *
 * ShouldQueue para nunca bloquear a resposta do request.
 */
class OtpCodeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Remetente com nome neutro "Limen" sobre o endereço padrão.
            from: new Address(config('mail.from.address'), 'Limen'),
            // Assunto neutro — não cita nada que denuncie a plataforma.
            subject: 'Seu código de acesso',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.otp-code',
            with: [
                'code' => $this->code,
                'minutes' => OtpCode::EXPIRY_MINUTES,
            ],
        );
    }
}
