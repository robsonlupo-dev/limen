<?php

/**
 * Comportamento do cadastro (feat/signup-email-gate-and-whatsapp-optin).
 */

return [
    /**
     * Tela de boas-vindas pós-cadastro (pré-lançamento).
     *
     * Quando LIGADO, logo após o cadastro o membro e a criadora caem na tela
     * `verification.notice` (o "pré-cadastro recebido" rico: confirme o e-mail,
     * veja os bônus, compartilhe seu convite, opt-in de WhatsApp) em vez de ir
     * direto para a selfie de KYC (membro) ou o onboarding (performer). É o funil
     * de CRESCIMENTO do pré-lançamento: a prioridade agora é confirmar e-mail +
     * indicar + deixar o WhatsApp, não completar o KYC.
     *
     * Quando DESLIGADO, volta ao destino clássico (membro → KYC, performer →
     * onboarding). Vira isto no go-live, quando o KYC passa a ser o próximo passo
     * imediato. O gate do KYC (EnsureMemberVerified) segue intacto nos dois casos:
     * ele só é adiado, não removido — o membro é levado ao KYC quando navega para
     * as áreas de membro.
     */
    'prelaunch_welcome' => (bool) env('SIGNUP_PRELAUNCH_WELCOME', true),
];
