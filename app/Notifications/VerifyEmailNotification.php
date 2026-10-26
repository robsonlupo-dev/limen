<?php

namespace App\Notifications;

use App\Support\SignupWelcomeData;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    use Queueable;

    /**
     * E-mail de confirmação PT-BR, agora role-aware (membro × criadora) e com a
     * explicação dos bônus (feat/signup-email-gate-and-whatsapp-optin) — a mesma
     * história da tela de pós-cadastro, pela MESMA fonte (SignupWelcomeData), para
     * dar à pessoa um motivo concreto de confirmar o e-mail. A URL aponta para a
     * rota web `verification.verify` (nunca a API).
     */
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $data = app(SignupWelcomeData::class)->for($notifiable);

        $isPerformer = ($data['role'] ?? 'member') === 'performer';

        return (new MailMessage)
            ->subject($isPerformer
                ? 'Falta 1 clique para reservar sua vaga de Criadora Fundadora'
                : 'Falta 1 clique para cruzar a porta — Limen')
            ->markdown('emails.verify-email', [
                'verificationUrl' => $url,
                'isPerformer' => $isPerformer,
                'welcome' => $data,
            ]);
    }
}
