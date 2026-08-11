<?php

/*
|--------------------------------------------------------------------------
| Texto de orientação da intro de voz (feat/voice-intro-polish)
|--------------------------------------------------------------------------
|
| O Pest não renderiza Vue, então o que ESTE teste protege é o TEXTO de
| orientação da tela de gravação — o enquadramento que a moderação vai cobrar
| depois. Uma regressão silenciosa que apagasse o aviso deixaria a performer sem
| saber que não pode passar contato por áudio (e ela seria recusada sem entender).
|
| Varredura estática de arquivo — fica em tests/Unit (não precisa de banco).
*/

it('a tela de gravação orienta: voz é convite/isca, é pública/identificável, sem contato, e passa por análise', function () {
    $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Performer/VoiceIntro/Edit.vue');

    expect($vue)->not->toBeFalse()
        // Isca para atrair, não canal de contato.
        ->and($vue)->toContain('convite')
        ->and($vue)->toContain('despertar curiosidade')
        // Pública + identificável (opt-in consciente).
        ->and($vue)->toContain('pública')
        ->and($vue)->toContain('identificável')
        // Proibição explícita de contato/encontro + consequência.
        ->and($vue)->toContain('passar contato')
        ->and($vue)->toContain('não são aprovados')
        // Análise humana antes de publicar.
        ->and($vue)->toContain('passa por');
});
