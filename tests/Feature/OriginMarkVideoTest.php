<?php

use App\Services\WatermarkService;

/**
 * Marca de ORIGEM no VÍDEO (Onda B §4, peça 2c). O caminho de ffmpeg em si é validado
 * pelo `php artisan origin-mark:video-selftest` (a suíte não roda ffmpeg); aqui cobrimos
 * o GATE (interruptor próprio, OFF por padrão) e a geração do overlay de RODAPÉ (GD),
 * que PULA quando a fonte TTF não está no ambiente — como o WatermarkTest.
 */
function omvFontPresent(): bool
{
    return is_file((string) config('custom_order.watermark.font'));
}

it('videoEnabled é false por padrão (interruptor OFF), mesmo com a fonte presente', function () {
    config()->set('custom_order.watermark.font', base_path('composer.json')); // "fonte" existe
    config()->set('origin_mark.video_enabled', false);

    expect(App\Support\OriginMark::videoEnabled())->toBeFalse();
});

it('videoEnabled é false quando ligado mas sem fonte (fail-open de branding)', function () {
    config()->set('origin_mark.video_enabled', true);
    config()->set('custom_order.watermark.font', '/nao/existe.ttf');

    expect(App\Support\OriginMark::videoEnabled())->toBeFalse();
});

it('videoEnabled é true quando ligado e a fonte existe', function () {
    config()->set('origin_mark.video_enabled', true);
    config()->set('custom_order.watermark.font', base_path('composer.json'));

    expect(App\Support\OriginMark::videoEnabled())->toBeTrue();
});

it('buildFooterOverlayPng gera um PNG transparente do tamanho pedido', function () {
    if (! omvFontPresent()) {
        $this->markTestSkipped('Fonte da marca ausente neste ambiente.');
    }

    $dest = tempnam(sys_get_temp_dir(), 'omv').'.png';
    app(WatermarkService::class)->buildFooterOverlayPng(720, 1280, 'Limen · @teste', $dest);

    $info = getimagesize($dest);
    expect($info)->not->toBeFalse()
        ->and($info[2])->toBe(IMAGETYPE_PNG)
        ->and($info[0])->toBe(720)
        ->and($info[1])->toBe(1280)
        ->and(filesize($dest))->toBeGreaterThan(0);

    @unlink($dest);
});
