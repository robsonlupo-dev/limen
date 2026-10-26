<?php

/**
 * Integração com o Chatwoot (CRM de operação do Limen, no servidor separado do
 * Contabo — inbox.limen.dev.br). O push de contato do opt-in de WhatsApp é
 * OPCIONAL e desligado por padrão: o banco (`users.whatsapp` + `whatsapp_consent_at`)
 * é a fonte da verdade do consentimento; o Chatwoot é só um destino de cópia para o
 * time operar a abordagem. Enquanto `enabled=false`, o PushContactToChatwoot vira
 * no-op (o opt-in é gravado normalmente). Ligar só depois de preencher as credenciais
 * no `.env` do servidor — mesma disciplina de kill-switch das integrações não cobertas
 * pela suíte (ex.: watermark).
 */

return [
    'enabled' => (bool) env('CHATWOOT_ENABLED', false),

    // Base da API do Chatwoot (sem barra final), ex.: https://inbox.limen.dev.br
    'base_url' => rtrim((string) env('CHATWOOT_BASE_URL', ''), '/'),

    // Conta e caixa de entrada onde o contato é criado/atualizado.
    'account_id' => env('CHATWOOT_ACCOUNT_ID'),
    'inbox_id' => env('CHATWOOT_INBOX_ID'),

    // Token de acesso de API (bot/agente). NUNCA no Git — só no .env do servidor.
    'api_token' => env('CHATWOOT_API_TOKEN'),

    // Rótulo aplicado ao contato que fez opt-in para o aviso de go-live.
    'optin_label' => env('CHATWOOT_OPTIN_LABEL', 'optin-golive'),

    // Timeout curto: o push roda em fila (job), mas não deve pendurar o worker.
    'timeout' => (int) env('CHATWOOT_TIMEOUT', 8),
];
