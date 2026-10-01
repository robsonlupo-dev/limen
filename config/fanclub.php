<?php

/*
|--------------------------------------------------------------------------
| Fã-Clube da performer (Onda 4 — fork da assinatura) — CICLO e CARÊNCIA
|--------------------------------------------------------------------------
| Só tempo aqui. Dinheiro (piso/teto de preço, split, tipos de ledger) vive em
| config/monetization.php (bloco `fanclub` + payout), como no custom_order.php.
|
| Ciclo: assinar → cobra na hora → renova no dia do ciclo (debita o saldo) →
| sem saldo entra em carência (avisa) → carência vencida sem recarga → pausa.
| Desvincular mantém acesso até o fim do ciclo pago, aí encerra.
*/

return [
    // Duração do ciclo da assinatura, em dias. 30 = "mês comercial" simples e previsível
    // (não usa mês-calendário para a cobrança ser uniforme — evita o 31→28 da virada).
    'cycle_days' => (int) env('FANCLUB_CYCLE_DAYS', 30),

    // Avisa-e-pausa: ao renovar sem saldo, a assinatura entra em carência por ATÉ isto;
    // durante a carência o acesso é mantido e o membro é avisado. Vencida sem recarga, pausa.
    'grace_days' => (int) env('FANCLUB_GRACE_DAYS', 3),

    // Teto de assinaturas ATIVAS por membro (anti-engano/anti-flood; o limite real de
    // gasto é o saldo). Alto o bastante para não atrapalhar uso legítimo.
    'max_active_per_member' => (int) env('FANCLUB_MAX_ACTIVE_PER_MEMBER', 50),

    /*
    | Sinal de baleia (docs/FORK_ASSINATURA.md §5.1): a performer vê o assinante por
    | FanAlias + selo de tier + uma FAIXA de gasto (nunca o número exato — canal lateral).
    | As faixas: "novo" (1º ciclo), "recorrente" (renovou ≥1 vez), "alto apoiador"
    | (acumulou >= high_tokens em assinatura com ela). Calibrável; limites conservadores.
    | O roster só aparece acima do PISO de anonimato (config interest.anonymity_floor).
    */
    'whale' => [
        'recorrente_charges' => (int) env('FANCLUB_WHALE_RECORRENTE_CHARGES', 2),
        'alto_tokens' => (int) env('FANCLUB_WHALE_ALTO_TOKENS', 300),
    ],

    // Teto de linhas no roster (painel da performer; não é hot path, mas evita payload
    // gigante numa performer com muitos assinantes).
    'roster_limit' => (int) env('FANCLUB_ROSTER_LIMIT', 200),
];
