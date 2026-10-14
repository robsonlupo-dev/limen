<?php

use function Pest\Laravel\artisan;

/**
 * Gorjeta/presente DENTRO da chamada 1:1 (feat/in-call-gift-tip). É camada de
 * FRONT: o PrivateCall reusa os modais e endpoints já cobertos (TipService /
 * GiftService). Aqui garantimos por FONTE que a superfície existe e não regride —
 * os botões do membro, os modais, o sinal cosmético pelo canal de dados do LiveKit
 * e a reação que a outra ponta anima ao receber.
 */
function privateCallSource(): string
{
    return file_get_contents(resource_path('js/Components/PrivateCall.vue'));
}

it('mostra gorjeta e presente só para o membro com slug da performer', function () {
    $src = privateCallSource();

    // Gate: membro + slug presente.
    expect($src)->toContain('canSendGifts');
    expect($src)->toContain("isMember.value && !!props.performerSlug");

    // Props novas.
    expect($src)->toContain('performerSlug');
    expect($src)->toContain('performerName');

    // Botões que abrem os modais.
    expect($src)->toContain('showTipModal = true');
    expect($src)->toContain('showGiftModal = true');
    expect($src)->toContain('Enviar gorjeta');
    expect($src)->toContain('Enviar presente');
});

it('reusa os modais autocontidos de gorjeta e presente', function () {
    $src = privateCallSource();

    expect($src)->toContain("import TipModal from '@/Components/TipModal.vue'");
    expect($src)->toContain("import GiftModal from '@/Components/GiftModal.vue'");
    expect($src)->toContain('<TipModal');
    expect($src)->toContain('<GiftModal');
    expect($src)->toContain('@sent="onTipSent"');
    expect($src)->toContain('@sent="onGiftSent"');
});

it('membro anima a reação local e a performer recebe pelo servidor', function () {
    $src = privateCallSource();

    // A performer ouve o canal pessoal (trilho confiável do servidor), NÃO mais o
    // pacote cliente-a-cliente do LiveKit.
    expect($src)->toContain('reactionChannel = window.Echo.private(`user.${uid}`)');
    expect($src)->toContain("reactionChannel.listen('.call.reaction', onCallReaction)");
    expect($src)->toContain('function onCallReaction');
    // Sai só do próprio callback (canal compartilhado).
    expect($src)->toContain("stopListening('.call.reaction', onCallReaction)");
    // A perna LiveKit data foi removida.
    expect($src)->not->toContain('publishData');
    expect($src)->not->toContain('RoomEvent.DataReceived');

    // Reação local + atualização imediata do saldo (banner não defasa).
    expect($src)->toContain('showReaction');
    expect($src)->toContain('recomputeMinutesLeft()');

    // Ícone por tipo (presente = GiftIcon, gorjeta = TokenCoin).
    expect($src)->toContain('<GiftIcon');
    expect($src)->toContain('<TokenCoin');
});

it('GiftModal entrega o presente escolhido no evento sent', function () {
    $src = file_get_contents(resource_path('js/Components/GiftModal.vue'));

    expect($src)->toContain('gift_slug: selectedSlug.value');
    expect($src)->toContain('gift_name: selectedGift.value?.name');
});

it('Catalog/Show passa slug, nome e pacotes de token para a chamada', function () {
    $src = file_get_contents(resource_path('js/Pages/Catalog/Show.vue'));

    expect($src)->toContain(':performer-slug="performer.slug"');
    expect($src)->toContain(':performer-name="performer.stage_name"');
    expect($src)->toContain(':token-packages="tokenPackages"');
    expect($src)->toContain(':needs-cpf="needsCpf"');
});
