<?php

/*
|--------------------------------------------------------------------------
| Sanitização de áudio (App\Services\VoiceProcessingService) — feat/voice-intro
|--------------------------------------------------------------------------
|
| A intro de voz enviada pela performer é HOSTIL por definição (arquivo do
| remetente). Antes de ser servida ela é re-encodada por ffmpeg — o arquivo
| servido deixa de ser o arquivo enviado, o que mata metadado embutido
| (ID3/GPS/device — áudio de celular carrega tudo isso) e qualquer stream
| estranho (capa/vídeo num .m4a = vetor de polyglot). Mesmo princípio do
| VideoProcessingService (Sprint 16), adaptado a áudio: alvo único MP3, mono.
|
| ffmpeg/ffprobe são os MESMOS binários do vídeo (já instalados no servidor).
| Config PRÓPRIO, não o de vídeo, porque os tetos (20s/5MB) e o codec são de
| áudio — misturar os dois faria uma mudança no vídeo mexer no áudio sem querer.
|
*/

return [

    // Binários. Compartilham o env do vídeo por padrão (é o mesmo ffmpeg), com
    // override próprio se o deploy precisar.
    'ffmpeg' => env('VOICE_FFMPEG_BINARY', env('FFMPEG_BINARY', 'ffmpeg')),
    'ffprobe' => env('VOICE_FFPROBE_BINARY', env('FFPROBE_BINARY', 'ffprobe')),

    // Teto de TAMANHO do upload (bytes). Recusado ANTES de processar (Form Request
    // + serviço). 5 MB — folga larga para 20s de voz (um MP3 128k de 20s ≈ 320 KB;
    // o teto cobre WAV/FLAC crus enviados como arquivo).
    'max_bytes' => 5 * 1024 * 1024,

    // Teto de DURAÇÃO (segundos). Lido por ffprobe no upload → 422 se exceder
    // (quando o container traz a duração no header). Gravação sem duração no header
    // (webm de MediaRecorder) é reconferida no job sobre o MP3 já processado.
    'max_duration_seconds' => 20,

    // Timeout do RE-ENCODE (job assíncrono). Áudio de 20s re-encoda em ~1s; um
    // arquivo hostil que trave o ffmpeg não deve prender o worker para sempre.
    'process_timeout' => 120,

    // Timeout do ffprobe de duração — CURTO e próprio. Roda SÍNCRONO no request de
    // upload (gate de duração); não pode herdar o do re-encode. Só lê o header.
    'probe_timeout' => 15,

    // Codec de saída — MP3 (libmp3lame), mono, 128 kbps, 44.1 kHz. MP3 é o formato
    // de <audio> mais universal e trivialmente seekável por Range (sem moov atom).
    // Voz não precisa de estéreo — mono metade do tamanho, sem perda perceptível.
    'audio_codec' => 'libmp3lame',
    'bitrate' => '128k',
    'sample_rate' => 44100,
    'channels' => 1,

];
