<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chat / mensagens (canal aberto pós-desbloqueio de Interesse)
    |--------------------------------------------------------------------------
    | Ver docs/COMMUNICATION_ECONOMY.md §2 e docs/INTEREST_SYSTEM_SPEC.md §5.
    | O canal só existe depois que o membro desbloqueia o Interesse da performer;
    | a performer manda a 1ª mensagem grátis. O membro paga por mensagem, exceto
    | se tiver um Círculo ativo (chat livre).
    */

    // Custo, em tokens, de cada mensagem enviada por um membro SEM Círculo ativo.
    // Membro assinante e a performer nunca pagam. Débito via token_ledger
    // (append-only); a performer é creditada pelo split_pct dela (como a gorjeta).
    'message_cost' => (int) env('CHAT_MESSAGE_COST', 2),

    // Tamanho máximo do corpo de uma mensagem, em caracteres.
    'max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 1000),
];
