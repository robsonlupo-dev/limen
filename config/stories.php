<?php

/**
 * Recursos sociais em torno de story/perfil (roadmap: docs/ROADMAP_SOCIAL.md).
 * Limites por-feature, valores via env com default. Nenhum preço aqui (a Onda 1
 * não mexe em token); números de dinheiro seguem em config/monetization.php.
 */

return [
    /*
    | Status do dia da performer (Onda 1a). Texto curto sobre o avatar, com
    | contagem regressiva opcional. Expira sozinho após `ttl_hours`.
    */
    'status' => [
        'max_length' => (int) env('STATUS_MAX_LENGTH', 140),
        'ttl_hours' => (int) env('STATUS_TTL_HOURS', 24),
        'label_max_length' => 40,
    ],

    /*
    | Destaques / Highlights (Onda 1a — PR seguinte). A mídia é COPIADA para
    | armazenamento permanente (o story some em 24h), então há teto de coleções e
    | de itens por coleção para poupar disco do servidor (2 vCPU / ~25 GB livres).
    */
    'highlights' => [
        'max_collections_per_performer' => (int) env('HIGHLIGHTS_MAX_COLLECTIONS', 10),
        'max_items_per_collection' => (int) env('HIGHLIGHTS_MAX_ITEMS', 30),
        'title_max_length' => 30,
    ],
];
