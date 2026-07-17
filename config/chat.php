<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chat / mensagens (canal aberto pós-desbloqueio de Interesse)
    |--------------------------------------------------------------------------
    | Ver docs/COMMUNICATION_ECONOMY.md §2 e docs/INTEREST_SYSTEM_SPEC.md §5.
    | O canal só existe depois que o membro desbloqueia o Interesse da performer
    | (a performer inicia — não há contato frio do membro). Para conversar:
    |   - Assinante de qualquer Círculo ativo: chat livre, histórico permanente.
    |   - Membro sem assinatura: paga um acesso por performer (janela de dias).
    */

    // Custo, em tokens, de um acesso ao chat de uma performer (membro sem
    // Círculo). Debitado via token_ledger append-only; a performer é creditada
    // pelo split_pct dela (como a gorjeta). Renovar cobra o mesmo valor.
    'access_cost' => (int) env('CHAT_ACCESS_COST', 50),

    // Dias de acesso total (envio + leitura) a partir do desbloqueio/renovação.
    'access_days' => (int) env('CHAT_ACCESS_DAYS', 30),

    // Dias de carência APÓS o vencimento: o histórico fica visível porém
    // bloqueado (sem envio, cada mensagem marcada locked) até este prazo; depois
    // as mensagens são soft-deletadas (retidas no servidor, ocultas na UI).
    'grace_days' => (int) env('CHAT_GRACE_DAYS', 15),

    // Tamanho máximo do corpo de uma mensagem, em caracteres.
    'max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 1000),
];
