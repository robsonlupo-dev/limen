<?php

namespace App\Mail;

use App\Services\DeletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Aviso de exclusão agendada. Serve a duas pessoas diferentes: o titular que
 * pediu (confirma o prazo) e o titular que NÃO pediu — para quem este e-mail é
 * o único sinal de que alguém com acesso à sessão mandou apagar a conta. Por
 * isso o link de cancelamento vem sempre, mesmo para quem pediu.
 *
 * Sem PII no corpo: nome, CPF ou histórico aqui só recriariam em SMTP e backup
 * exatamente o dado que o e-mail anuncia que será destruído.
 */
class AccountDeletionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?Carbon $scheduledAt,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Exclusão da sua conta agendada — Limen');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account.deletion-requested',
            with: [
                'scheduledAt' => $this->scheduledAt,
                'graceDays' => DeletionService::GRACE_DAYS,
                'tokenHours' => DeletionService::TOKEN_TTL_HOURS,
                'confirmUrl' => route('account.deletion.confirm', ['token' => $this->token]),
                'settingsUrl' => route('consumer.settings'),
            ],
        );
    }
}
