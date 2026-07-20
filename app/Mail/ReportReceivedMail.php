<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta operacional: uma denúncia entrou na fila. É o que transforma o
 * registro em `reports` num sinal — sem ele a linha esperaria alguém abrir o
 * painel por conta própria, e denúncia de conteúdo ilegal tem relógio correndo.
 *
 * NÃO carrega o corpo da denúncia nem a identidade do denunciante. O `details`
 * é texto livre e pode conter PII de terceiros; a caixa de e-mail do admin não
 * é storage adequado para isso (fica em trânsito, em backup, em índice de
 * busca). O e-mail é um ponteiro: o conteúdo se lê autenticado em /admin/reports.
 */
class ReportReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        $urgent = in_array($this->report->reason, ['underage_content', 'non_consensual', 'coercion'], true);

        return new Envelope(
            // Sem o alvo no assunto: o par (perfil identificável + acusação
            // de conteúdo com menor) não pode transitar por SMTP, backup e
            // índice de busca da caixa. A categoria sozinha não identifica
            // ninguém e é o que dá a triagem.
            subject: sprintf(
                '[Limen]%s Nova denúncia #%d — %s',
                $urgent ? ' [URGENTE]' : '',
                $this->report->id,
                $this->report->reason,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.reports.received',
            with: [
                'reportId' => $this->report->id,
                'reason' => $this->report->reason,
                'createdAt' => $this->report->created_at,
                'hasDetails' => filled($this->report->details),
                'panelUrl' => url('/admin/reports'),
            ],
        );
    }
}
