<?php

/**
 * Controles da chamada 1:1 (fix/call-controls-and-end). A tela da chamada ganhou
 * mudo de microfone/câmera (os dois lados), encerramento gracioso quando a OUTRA
 * pessoa sai (ParticipantDisconnected) ou a sala fecha (Disconnected), e um botão
 * Fechar no overlay final — para a performer/membro nunca ficar preso numa tela
 * preta sem saída (bug do UAT 95.1/95.2 + "fica presa"). Asserções por fonte do
 * componente, no padrão do OnlinePresenceCallTest. `pcc`.
 */
function privateCallSrc(): string
{
    return file_get_contents(resource_path('js/Components/PrivateCall.vue'));
}

it('a chamada tem mudo de microfone e de camera para os dois lados', function () {
    expect(privateCallSrc())
        ->toContain('toggleMic')
        ->toContain('setMicrophoneEnabled')
        ->toContain('toggleCam')
        ->toContain('setCameraEnabled');
});

it('encerra graciosamente quando a outra pessoa sai ou a sala fecha', function () {
    expect(privateCallSrc())
        // a saída do outro participante encerra deste lado…
        ->toContain('RoomEvent.ParticipantDisconnected')
        // …e a desconexão da sala também passa pela despedida (não fica em silêncio).
        ->toContain('RoomEvent.Disconnected');
});

it('o overlay final sempre tem um botao Fechar (nunca fica sem saida)', function () {
    $src = privateCallSrc();
    // O botão Fechar chama finish(), que é idempotente (trava `finished`).
    expect($src)
        ->toContain('Fechar')
        ->toContain('if (finished) return');
});
