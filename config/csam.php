<?php

/*
|--------------------------------------------------------------------------
| Anti-CSAM (App\Services\CsamScanService) — Sprint 16, MVP
|--------------------------------------------------------------------------
|
| Hash perceptual de toda imagem no upload, conferido contra a lista local
| (csam_hashes). MVP: match EXATO do phash (indexado, "básico"). Near-match por
| distância de Hamming e a integração PhotoDNA real são follow-up (a lista real
| depende de parceria NCMEC/IWF + CNPJ).
|
*/

return [

    // Liga/desliga a verificação. Sobe LIGADO (é bloqueador de go-live). Desligar é
    // decisão de PO — a verificação é fail-open por design, então "ligado com lista
    // vazia" nunca bloqueia upload legítimo.
    'enabled' => env('CSAM_SCAN_ENABLED', true),

    // Fonte padrão gravada em csam_hashes quando o import não informa uma.
    'default_source' => 'manual',

];
