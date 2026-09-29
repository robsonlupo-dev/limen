<?php

/*
|--------------------------------------------------------------------------
| Encomenda sob medida com escrow (Onda 4, §4.3) — JANELAS e LIMITES
|--------------------------------------------------------------------------
| Só tempo e contagem aqui. Dinheiro (piso/teto de preço, split, tipos de
| ledger) vive em config/monetization.php, como no scheduled_call.php.
|
| Ciclo: pedido → aceite (escrow) → entrega → [aprovação do membro OU fim da
| janela de contestação] → libera; recusa/expiração/não-entrega → estorna.
*/

return [
    // A performer tem ATÉ isto para aceitar/recusar; depois o pedido expira (sem
    // cobrança — o débito só ocorre no aceite).
    'accept_window_hours' => (int) env('CUSTOM_ORDER_ACCEPT_WINDOW_HOURS', 48),

    // Depois de aceitar, a performer tem ATÉ isto para entregar; senão estorna 100%.
    'deliver_window_hours' => (int) env('CUSTOM_ORDER_DELIVER_WINDOW_HOURS', 168), // 7 dias

    // Depois da entrega, o membro tem ATÉ isto para aprovar ou CONTESTAR; sem ação, o
    // dinheiro libera automaticamente (auto-release).
    'dispute_window_hours' => (int) env('CUSTOM_ORDER_DISPUTE_WINDOW_HOURS', 72), // 3 dias

    // Teto de pedidos ATIVOS (requested/accepted/delivered/disputed) por membro e por
    // par (membro×performer) — anti-flood, como o teto de reservas por membro.
    'max_active_per_member' => (int) env('CUSTOM_ORDER_MAX_ACTIVE_PER_MEMBER', 10),
    'max_active_per_pair' => (int) env('CUSTOM_ORDER_MAX_ACTIVE_PER_PAIR', 3),

    // Tamanho da descrição do pedido.
    'description_max_length' => 500,
];
