<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\OriginMark;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Marca de ORIGEM (Onda B §4 do roadmap Fatal Model): o TEXTO ("Limen" vs
 * "Limen · @slug") e o gate de aplicação. O DESENHO da marca é do WatermarkService
 * (coberto por WatermarkTest). Prefixo `om` nos helpers (globais no Pest).
 */
function omPerformer(array $profileAttributes = []): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = $profileAttributes['stage_name'] ?? 'Origin'.$n;
    unset($profileAttributes['stage_name']);

    return $user->performerProfile()->create(array_merge([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ], $profileAttributes));
}

/** Aponta o config da fonte para um arquivo que EXISTE, para `enabled()` não depender do box. */
function omUseRealFont(): void
{
    config()->set('custom_order.watermark.font', base_path('composer.json'));
}

it('textFor inclui o @slug quando o handle está ligado', function () {
    $p = omPerformer(['origin_mark_handle' => true]);

    expect(OriginMark::textFor($p))->toBe('Limen · @'.$p->slug);
});

it('textFor mostra só "Limen" quando o handle está desligado', function () {
    $p = omPerformer(['origin_mark_handle' => false]);

    expect(OriginMark::textFor($p))->toBe('Limen');
});

it('textFor cai no default do config quando a coluna é nula', function () {
    $p = omPerformer();
    $p->origin_mark_handle = null; // linha antiga, antes da migration

    config()->set('origin_mark.handle_default', true);
    expect(OriginMark::textFor($p))->toBe('Limen · @'.$p->slug);

    config()->set('origin_mark.handle_default', false);
    expect(OriginMark::textFor($p))->toBe('Limen');
});

it('enabled é false quando o config está desligado', function () {
    config()->set('origin_mark.enabled', false);
    omUseRealFont();

    expect(OriginMark::enabled())->toBeFalse();
});

it('enabled é false (fail-open de branding) quando a fonte não existe', function () {
    config()->set('origin_mark.enabled', true);
    config()->set('custom_order.watermark.font', '/caminho/que/nao/existe.ttf');

    expect(OriginMark::enabled())->toBeFalse();
});

it('enabled é true quando ligado e a fonte existe', function () {
    config()->set('origin_mark.enabled', true);
    omUseRealFont();

    expect(OriginMark::enabled())->toBeTrue();
});

it('textIfEnabled devolve null quando desligado e o texto quando ligado', function () {
    $p = omPerformer(['origin_mark_handle' => false]);

    config()->set('origin_mark.enabled', false);
    omUseRealFont();
    expect(OriginMark::textIfEnabled($p))->toBeNull();

    config()->set('origin_mark.enabled', true);
    expect(OriginMark::textIfEnabled($p))->toBe('Limen');
});

it('origin_mark_handle é um booleano persistido e casteado', function () {
    $p = omPerformer(['origin_mark_handle' => false]);

    $fresh = $p->fresh();
    expect($fresh->origin_mark_handle)->toBeFalse()
        ->and($fresh->origin_mark_handle)->toBeBool();
});
