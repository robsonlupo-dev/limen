<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sistema de Interesse Controlado (Performer → Membro)
    |--------------------------------------------------------------------------
    | Ver docs/INTEREST_SYSTEM_SPEC.md. A performer sinaliza interesse (sinal
    | binário, sem texto); o membro paga tokens para desbloquear quem enviou.
    */

    // Custo, em tokens, para o membro desbloquear (revelar) uma performer.
    // Débito 100% plataforma — a performer NÃO recebe crédito do desbloqueio.
    'unlock_cost' => (int) env('INTEREST_UNLOCK_COST', 15),

    // Teto de interesses que uma performer pode enviar por dia. É o piso;
    // tiers superiores elevam o limite (tabela por tier é follow-up).
    'daily_limit' => (int) env('INTEREST_DAILY_LIMIT', 5),

    // Uma performer não pode reenviar interesse ao mesmo membro dentro desta
    // janela (em dias), mesmo sem desbloqueio — evita "cutucadas" repetidas.
    'cooldown_days' => (int) env('INTEREST_COOLDOWN_DAYS', 30),
];
