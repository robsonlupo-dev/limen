<?php

/*
|--------------------------------------------------------------------------
| Sanitização de vídeo (App\Services\VideoProcessingService) — Sprint 16
|--------------------------------------------------------------------------
|
| Vídeo enviado como conteúdo permanente é HOSTIL por definição (arquivo do
| remetente). Antes de ser servido ele é re-encodado por ffmpeg — o arquivo
| servido deixa de ser o arquivo enviado, o que mata payload embutido (polyglot)
| e metadado (GPS/device). Mesmo princípio do ImageProcessingService (§ 1.4),
| adaptado a vídeo (GD não processa vídeo).
|
*/

return [

    // Binários. Nome simples resolvido pelo PATH do worker; caminho absoluto se o
    // deploy precisar fixar. Instalados via `apt install ffmpeg` (ffmpeg+ffprobe).
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

    // Teto de TAMANHO do upload (bytes). Recusado ANTES de processar (Form Request
    // + serviço). 500 MB.
    'max_bytes' => 500 * 1024 * 1024,

    // Teto de DURAÇÃO (segundos). Lido por ffprobe no upload → 422 se exceder,
    // antes de dispensar o job de re-encode (caro). 10 min.
    'max_duration_seconds' => 600,

    // Poster: frame capturado neste segundo, escalado para esta largura, JPEG.
    'thumbnail_second' => 1,
    'thumbnail_width' => 640,

    // Timeout do RE-ENCODE (job assíncrono). Re-encode de 10 min de vídeo não deve
    // prender o worker para sempre — falha e marca a peça como failed.
    'process_timeout' => 1800, // 30 min

    // Timeout do ffprobe de duração — CURTO e próprio. Roda SÍNCRONO no request de
    // upload (gate de duração), então não pode herdar os 30 min do re-encode: um
    // arquivo hostil que trave o ffprobe seguraria o worker web. Só lê o header.
    'probe_timeout' => 20, // segundos

    // Codecs do container de saída — H.264 (libx264) + AAC em MP4. `faststart`
    // move o moov atom para o início (streaming progressivo).
    'video_codec' => 'libx264',
    'audio_codec' => 'aac',
    'crf' => 23, // qualidade/tamanho (menor = melhor/maior)
    'preset' => 'medium',

];
