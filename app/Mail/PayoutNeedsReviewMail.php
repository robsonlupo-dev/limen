<?php

namespace App\Mail;

use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta operacional: um payout foi estacionado em needs_review pelo reconcile
 * (transferência não confirmada automaticamente após 2h de tentativas). Nenhum
 * token foi movido — a reserva continua de pé —, mas um humano precisa decidir
 * entre reprocessar (requeue) ou resolver manualmente. Sem este email o sinal
 * seria apenas uma linha no audit log. Não carrega PII: nada de chave PIX.
 */
class PayoutNeedsReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payout $payout) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Limen] Payout #{$this->payout->id} aguarda revisão manual");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payouts.needs-review',
            with: [
                'payoutId' => $this->payout->id,
                'performerId' => $this->payout->performer_id,
                'amountBrl' => $this->payout->amount_brl,
                'tokens' => $this->payout->tokens,
                'createdAt' => $this->payout->requested_at,
                'reason' => 'Transferência não pôde ser confirmada automaticamente após 2h de tentativas.',
            ],
        );
    }
}
