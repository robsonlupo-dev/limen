<?php

/**
 * Programa de indicação (feat/referral-program). Desenho canônico:
 * `docs/PROGRAMA_INDICACAO.md`.
 *
 * Nenhum valor de recompensa vive em código — tudo aqui, para ajuste sem deploy
 * de lógica. Dinheiro NUNCA aparece: o bônus é em TOKENS e é NÃO-SACÁVEL (o
 * entry_type `referral_bonus` fica fora do allowlist de payout, por construção).
 */

return [
    // Interruptor mestre. Desligado por padrão — liga no go-live do programa.
    'enabled' => env('REFERRAL_PROGRAM_ENABLED', false),

    // Janela anti-estorno entre qualificar e creditar (cobre contestação PIX/MED e
    // chargeback antecipado). Só depois disso os dois lados são creditados.
    'hold_days' => (int) env('REFERRAL_HOLD_DAYS', 14),

    // Recompensa FIXA por conversão qualificada (tokens inteiros). Os dois lados
    // ganham. Ver `docs/PROGRAMA_INDICACAO.md` §1.
    'rewards' => [
        // Indicado (membro OU performer) fez a 1ª compra de pacote confirmada.
        'member_purchase' => [
            'referrer' => 10,
            'referred' => 5,
        ],
        // Performer indicada: KYC aprovado + 1º ganho vindo de um TERCEIRO pagador.
        'performer_first_earning' => [
            'referrer' => 40,
            'referred' => 25,
        ],
    ],

    // Prefixo do código gerado (ex.: LM-7F3K2). Só cosmético.
    'code_prefix' => env('REFERRAL_CODE_PREFIX', 'LM-'),
];
