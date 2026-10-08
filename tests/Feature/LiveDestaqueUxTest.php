<?php

/**
 * Destaque de live + UX da sala (feat/live-destaque-ux — achados do UAT Fase 9):
 * (1) card do catálogo com anel pulsante e selo maior quando ao vivo; (2) trilha
 * "Agora" com círculo de live maior e nome; (3) botão de sair da live no viewer
 * do membro; (4) gorjeta/presente registrados no fluxo do chat da sala; (5) feed
 * do console nomeando o presente. Asserções ESTÁTICAS por fonte (sem Vitest),
 * padrão do LiveGiftAndSelfViewTest. Prefixo `ldu`.
 */
function lduSrc(string $path): string
{
    return file_get_contents(resource_path($path));
}

it('o card do catalogo destaca quem esta ao vivo (anel pulsante + selo maior com tooltip)', function () {
    $src = lduSrc('js/Components/PerformerCard.vue');

    expect($src)->toContain('live-card-ring')
        ->toContain('Ao vivo agora — toque para entrar')
        // Com prefers-reduced-motion o anel fica estático, não some.
        ->toContain('prefers-reduced-motion');

    // AO VIVO tem precedência visual sobre o anel Maison.
    $liveAt = strpos($src, 'live-card-ring ring-2');
    $maisonAt = strpos($src, "ring-1 ring-limen-gold/80");
    expect($liveAt)->toBeLessThan($maisonAt);
});

it('a trilha Agora da mais presenca ao circulo de live (maior, selo e nome)', function () {
    $src = lduSrc('js/Components/NowStrip.vue');

    expect($src)->toContain('h-[76px] w-[76px]')  // maior que o círculo de story (62px)
        ->toContain('now-live-name')               // nome da performer sob o círculo
        ->toContain('está ao vivo — toque para entrar');
});

it('o membro tem botao de sair durante a live (nao so na tela de encerrada)', function () {
    $src = lduSrc('js/Components/LiveViewer.vue');

    expect($src)->toContain('Sair da live e voltar ao catálogo')
        // Só durante a transmissão — a tela de fim já tem o próprio botão.
        ->toContain('v-if="status !== \'ended\'"');
});

it('gorjeta e presente ficam registrados no fluxo do chat da sala', function () {
    $src = lduSrc('js/Components/LiveChat.vue');

    expect($src)->toContain(".listen('.live.chat', append)")             // o chat segue intacto
        ->toContain(".listen('.live.reaction', appendReaction)")          // novo: reações no fluxo
        ->toContain("kind: 'reaction'")
        // Remove SÓ o próprio callback: overlay (membro) e feed do console
        // (performer) ouvem `.live.reaction` no MESMO canal compartilhado.
        ->toContain("stopListening('.live.reaction', appendReaction)");
});

it('o feed do console nomeia o presente e diferencia gorjeta', function () {
    $src = lduSrc('js/Components/LiveReactionFeed.vue');

    expect($src)->toContain('function giftName')
        ->toContain("'gorjeta'");
});
