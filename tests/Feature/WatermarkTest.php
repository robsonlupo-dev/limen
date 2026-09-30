<?php

use App\Services\WatermarkService;
use Intervention\Image\ImageManager;

/**
 * Marca d'água da encomenda sob medida (Onda 4 §4.3). Exercita o WatermarkService de
 * verdade (GD + fonte TTF) — é a rede que valida a API do Intervention v3 e a presença da
 * fonte no servidor, já que o container de patch não roda GD. Pula se a fonte configurada
 * não existir (ambiente sem a TTF), em vez de falhar por infra.
 */
function wmFontPresent(): bool
{
    return is_file((string) config('custom_order.watermark.font'));
}

it('marca uma foto e devolve um JPEG válido de mesmas dimensões', function () {
    if (! wmFontPresent()) {
        $this->markTestSkipped('Fonte da marca d\'água ausente neste ambiente.');
    }

    $src = (string) ImageManager::gd()->create(600, 800)->fill('rgb(50,50,50)')->toJpeg(quality: 85);

    $out = app(WatermarkService::class)->applyToPhotoBytes($src, 'Fã #5454 · 29/09/2026');

    $info = getimagesizefromstring($out);
    expect($info)->not->toBeFalse()
        ->and($info[2])->toBe(IMAGETYPE_JPEG)
        ->and($info[0])->toBe(600)
        ->and($info[1])->toBe(800)
        // Marca queimada muda os bytes em relação ao original.
        ->and($out)->not->toBe($src);
});

it('gera um overlay PNG transparente do tamanho pedido', function () {
    if (! wmFontPresent()) {
        $this->markTestSkipped('Fonte da marca d\'água ausente neste ambiente.');
    }

    $path = tempnam(sys_get_temp_dir(), 'wm_test_').'.png';
    try {
        app(WatermarkService::class)->buildOverlayPng(320, 240, 'Fã #5454 · 29/09/2026', $path);

        expect(is_file($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(0);

        $info = getimagesize($path);
        expect($info[2])->toBe(IMAGETYPE_PNG)
            ->and($info[0])->toBe(320)
            ->and($info[1])->toBe(240);
    } finally {
        @unlink($path);
    }
});

it('labelFor monta FanAlias + data sem dado real', function () {
    $label = WatermarkService::labelFor('Fã #5454');
    expect($label)->toStartWith('Fã #5454 · ')
        ->and($label)->toMatch('/\d{2}\/\d{2}\/\d{4}$/');
});
