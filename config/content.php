<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Destaques da vitrine (fixar conteúdo) — roadmap social, Onda 3 (§ 3.1)
    |--------------------------------------------------------------------------
    |
    | Teto de peças que a performer pode fixar no topo da vitrine. Mantém o
    | "topo" significativo (estilo Insta). É regra de produto, aplicada no
    | PerformerContentService::setPinned — não é invariante de segurança.
    */
    'max_pinned' => (int) env('CONTENT_MAX_PINNED', 3),
];
