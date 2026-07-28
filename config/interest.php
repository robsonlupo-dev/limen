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
    //
    // Conta só a origem SEGUIDORES desde o Sprint 9 — a cota de visitantes é
    // separada, logo abaixo.
    'daily_limit' => (int) env('INTEREST_DAILY_LIMIT', 5),

    // Cota diária da origem VISITANTES (painel do dashboard), separada da de
    // seguidores por decisão do PO. Consequência a registrar: o teto DIÁRIO
    // TOTAL de uma performer passa de 5 para 8 (5 + 3).
    //
    // O número não é arbitrário do ponto de vista de privacidade e merece
    // atenção de quem for mexer nele: 3 é exatamente SLOT_MIN_K, o k-anonimato
    // por faixa do painel. Com 3 envios/dia a performer alcança uma faixa
    // inteira num único dia — ver a ressalva em docs/SECURITY_ISSUES.md.
    // Subir este valor barateia a sondagem; descer encarece.
    'visitor_daily_limit' => (int) env('INTEREST_VISITOR_DAILY_LIMIT', 3),

    // Uma performer não pode reenviar interesse ao mesmo membro dentro desta
    // janela (em dias), mesmo sem desbloqueio — evita "cutucadas" repetidas.
    'cooldown_days' => (int) env('INTEREST_COOLDOWN_DAYS', 30),

    // Piso de Anonimato: a performer só enxerga a lista de seguidores a partir
    // deste número de seguidores ativos. Com 1 ou 2 seguidores, "Membro #123"
    // deixa de ser anônimo — quem acabou de seguir sabe que é ele, e a performer
    // também. O piso dilui a lista antes de mostrá-la.
    'anonymity_floor' => (int) env('INTEREST_ANONYMITY_FLOOR', 5),

    // Mitigação de sybil no piso: só contas com esta idade (em dias) contam
    // para ATINGIR o piso. Sem isto, a performer cria 4 contas de consumidor,
    // segue a si mesma e desbloqueia a lista — o próximo seguidor de verdade
    // aparece sozinho, que é exatamente o que o piso existe para impedir.
    // Contas novas seguem normalmente e aparecem na lista quando o piso já foi
    // atingido por contas antigas; elas só não ajudam a atingi-lo.
    'anonymity_floor_account_age_days' => (int) env('ANONYMITY_FLOOR_ACCOUNT_AGE_DAYS', 7),
];
