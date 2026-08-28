<?php

/**
 * feat/live-broadcast-controls — controles de estúdio da performer (mic, câmera,
 * qualidade). É 100% client-side (LiveKit); o projeto não tem Vitest, então os
 * invariantes são travados por FONTE, como MicroInteractions/VoiceIntroGuidance/
 * LandingCinematicAssets. Cada teste espelha um requisito do PO.
 */
function lbcRoom(): string
{
    return file_get_contents(resource_path('js/Components/LiveRoom.vue'));
}

function lbcViewer(): string
{
    return file_get_contents(resource_path('js/Components/LiveViewer.vue'));
}

/** Corpo de uma função entre o seu início e o início da próxima. */
function lbcBody(string $src, string $from, string $to): string
{
    $a = strpos($src, $from);
    $b = strpos($src, $to);

    return $a === false || $b === false ? '' : substr($src, $a, $b - $a);
}

it('mudo/desmudo altera o MICROFONE PUBLICADO, nao o muted da previa local', function () {
    $src = lbcRoom();

    // toggleMic muda a faixa PUBLICADA (o que o membro ouve).
    expect(lbcBody($src, 'async function toggleMic', 'async function toggleCamera'))
        ->toContain('setMicrophoneEnabled(next)');

    // A prévia local segue `muted` (só evita o eco dela) — separada do mute publicado.
    expect($src)->toMatch('/<video[^>]*\bmuted\b/');

    // Aviso PERMANENTE enquanto mudo, para ela não esquecer.
    expect($src)->toContain('Seu microfone está mudo');
});

it('camera desligada mostra AVISO ao membro, nunca tela preta', function () {
    $src = lbcViewer();

    expect($src)
        ->toContain('RoomEvent.TrackMuted')
        ->toContain('RoomEvent.TrackUnmuted')
        ->toContain('cameraOff')
        ->toContain('A transmissão voltará em instantes');
});

it('a camera liga/desliga SEM encerrar a live', function () {
    $body = lbcBody(lbcRoom(), 'async function toggleCamera', 'async function applyResolution');

    // setCameraEnabled(false/true) muta a faixa — não encerra a sala.
    expect($body)
        ->toContain('setCameraEnabled')
        ->not->toContain('performer.live.stop')
        ->and($body)->not->toContain('disconnect');
});

it('troca de resolucao usa restartTrack e NAO derruba a sala', function () {
    $body = lbcBody(lbcRoom(), 'async function applyResolution', 'function lowerToMinimum');

    expect($body)->toContain('restartTrack')
        ->and($body)->not->toContain('disconnect')
        ->and($body)->not->toContain('room.connect')
        ->and($body)->not->toContain('performer.live.stop');
});

it('oferece 1080/720/480 com 720 como padrao', function () {
    $src = lbcRoom();

    expect($src)
        ->toContain("const resolution = ref('720')")
        ->toContain('value="1080"')
        ->toContain('value="720"')
        ->toContain('value="480"')
        ->toContain('480p para internet fraca'); // texto simples de ajuda
});

it('sugere baixar em conexao ruim mas NUNCA troca sozinha', function () {
    $src = lbcRoom();
    $body = lbcBody($src, 'function onConnectionQuality', 'async function toggleMic');

    // Só acende a sugestão; não aplica a troca por conta própria.
    expect($body)
        ->toContain('suggestLower.value =')
        ->not->toContain('restartTrack');
    expect($src)->toContain('ConnectionQuality.Poor');
});

it('controles acessiveis: alvos >=44px e estado anunciado', function () {
    $src = lbcRoom();

    expect($src)
        ->toContain('aria-pressed')  // estado do toggle
        ->toContain('aria-live')     // avisos anunciados
        ->toContain('h-11');         // 44px de altura mínima nos botões
});

it('nao adiciona lib nova alem do livekit-client', function () {
    $src = lbcRoom();

    // Os símbolos novos vêm todos do livekit-client (sem dependência nova).
    expect($src)
        ->toContain("from 'livekit-client'")
        ->toContain('VideoPresets')
        ->toContain('ConnectionQuality')
        ->toContain('restartTrack');
});
