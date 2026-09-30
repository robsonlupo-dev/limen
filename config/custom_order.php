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

    /*
    |--------------------------------------------------------------------------
    | Marca d'água na mídia entregue (Onda 4 §4.3)
    |--------------------------------------------------------------------------
    | Queima "Fã #NNNN · dd/mm/aaaa" (FanAlias + data — nunca dado real) em
    | diagonal repetida e semi-transparente na foto E no vídeo entregues, para
    | rastrear/desencorajar vazamento. Aplica SÓ nas encomendas sob medida (não
    | no cofre/PPV). Fail-closed: com a marca LIGADA, se ela falhar a entrega
    | falha (a performer reenvia) — nunca entrega sem marca.
    |
    | `enabled` nasce DESLIGADO: o caminho de vídeo (overlay via ffmpeg) não é
    | coberto pela suíte e dev==prod, então ligue só depois do self-test
    | (`php artisan custom-orders:watermark-selftest`) e de uma entrega real de
    | foto e de vídeo em limen.dev.br. Kill-switch por .env + config:cache.
    */
    'watermark' => [
        'enabled' => (bool) env('CUSTOM_ORDER_WATERMARK', false),
        // TTF para o texto (GD precisa de fonte de arquivo). Default do Debian.
        'font' => env('CUSTOM_ORDER_WATERMARK_FONT', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
        'opacity' => 0.22,   // 0..1 — sutil, mas visível
        'angle' => 30,       // diagonal
        // Tamanho da fonte como fração do menor lado da mídia (escala com a resolução).
        'size_ratio' => 0.045,
    ],
];
