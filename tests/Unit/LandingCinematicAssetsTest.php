<?php

/*
|--------------------------------------------------------------------------
| Mídia e CTA da landing cinematográfica (feat/landing-cinematic)
|--------------------------------------------------------------------------
|
| Varredura estática (tests/Unit — sem banco): garante que a mídia otimizada
| foi commitada, que cada peça respeita o teto de peso (a landing promete
| primeira dobra < 2s), e que o único CTA aponta para /cadastro. Se um asset
| sumir ou inchar num rebuild descuidado, isto quebra antes de ir ao ar.
|
| A invariante "zero terceiro" (nenhuma URL externa) é de ExternalAssetPolicyTest;
| aqui só conferimos que a landing referencia mídia SELF-HOST por caminho
| relativo /landing/*.
*/

function landingPath(string $rel = ''): string
{
    return dirname(__DIR__, 2).'/public/landing'.($rel ? "/{$rel}" : '');
}

// `fundo` (mármore limpo, feat/landing-marble-bg) substituiu `moldura` como fundo
// da cena do convite e da banda de waitlist. `moldura` CONTINUA no repo — segue
// sendo o og:image do cartão social (ver LandingCinematicTest) —, então permanece
// na varredura de peso.
const LANDING_IMAGE_STEMS = ['porta', 'portal', 'digital', 'silhueta', 'mascara', 'moldura', 'fundo'];

/** Teto por imagem WebP: 400 KB (pedido de otimização). */
const LANDING_WEBP_MAX = 400 * 1024;

/** Teto do vídeo de abertura: folga sobre a meta de ~2–3 MB. */
const LANDING_VIDEO_MAX = 3 * 1024 * 1024;

it('ships an optimized desktop + mobile WebP for every scene, each under 400 KB', function () {
    foreach (LANDING_IMAGE_STEMS as $stem) {
        foreach (["{$stem}.webp", "{$stem}-mobile.webp"] as $file) {
            $path = landingPath($file);

            expect(file_exists($path))->toBeTrue("Faltando asset otimizado da landing: public/landing/{$file}");
            expect(mime_content_type($path))->toBe('image/webp', "{$file} não é WebP");
            expect(filesize($path))->toBeLessThan(
                LANDING_WEBP_MAX,
                sprintf('%s tem %d KB (> 400 KB)', $file, filesize($path) / 1024),
            );
        }
    }
});

it('ships a compressed muted opening video under the weight ceiling', function () {
    $path = landingPath('abertura.mp4');

    expect(file_exists($path))->toBeTrue('Faltando o vídeo de abertura: public/landing/abertura.mp4');
    expect(filesize($path))->toBeLessThan(
        LANDING_VIDEO_MAX,
        sprintf('abertura.mp4 tem %.1f MB (> 3 MB)', filesize($path) / 1048576),
    );
});

it('does not ship the heavy source PNGs alongside the WebP', function () {
    // Os PNGs originais (2–7 MB cada) foram descartados após a conversão. Se um
    // voltar a ser commitado, o diretório volta a pesar dezenas de MB.
    foreach (LANDING_IMAGE_STEMS as $stem) {
        expect(file_exists(landingPath("{$stem}.png")))
            ->toBeFalse("PNG-fonte pesado voltou ao repo: public/landing/{$stem}.png");
    }
});

it('makes the waitlist the only landing CTA — no cadastro pre-launch', function () {
    $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Landing.vue');

    // Pré-lançamento: o ÚNICO CTA é a lista de espera.
    expect($vue)->toContain("route('waitlist.store')")
        ->toContain('id="lista-de-espera"');
    // O cadastro saiu da landing (o backend de /cadastro segue intacto; volta no
    // lançamento). Nenhum link para route('register') na tela.
    expect($vue)->not->toContain("route('register')");
});

it('hides the header account nav on the landing via the prelaunch flag', function () {
    $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Landing.vue');
    $layout = file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/GuestLayout.vue');

    // A landing lê a flag global e a passa ao layout.
    expect($vue)->toContain('features?.landing_prelaunch')
        ->toContain(':hide-account-nav="prelaunch"');
    // O layout esconde os botões de conta quando hideAccountNav é true.
    expect($layout)->toContain('hideAccountNav')
        ->toContain('v-if="!hideAccountNav"');
});

it('references landing media only by self-hosted relative /landing/ paths', function () {
    $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Landing.vue');

    // Cada cena aponta para /landing/<algo> — e nunca para um host externo. O fundo
    // das duas seções finais é o mármore `fundo.webp` (moldura saiu da TELA; segue só
    // como og:image no controller — ver LandingCinematicTest).
    expect($vue)->toContain('/landing/porta.webp')
        ->toContain('/landing/abertura.mp4')
        ->toContain('/landing/fundo.webp');

    preg_match_all('#["\'](/landing/[^"\']+)["\']#', $vue, $m);
    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $ref) {
        $file = basename($ref);
        expect(file_exists(landingPath($file)))->toBeTrue("Landing referencia mídia inexistente: {$ref}");
    }
});
