<?php

use App\Models\PerformerContent;
use App\Services\ContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * feat/content-showcase item 7: a prévia BORRADA substitui o placeholder cinza no
 * tile bloqueado. CRÍTICO — a imagem ORIGINAL nunca é servida a quem não pode ver, e
 * a borrada (baixa resolução + blur no servidor) não permite reconstruir o original.
 *
 * Reusa pcPerformer/pcMember/pcPublish de PermanentContentTest.
 */

it('serve a previa borrada de um conteudo bloqueado, e ela e minuscula (irreversivel)', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50); // pcPublish gera o blur no store

    $free = pcMember(); // não alcança Exclusivo → tile bloqueado

    // A prévia borrada é servível a quem NÃO pode ver (é a isca), sem exigir canView.
    $res = $this->actingAs($free)->get(route('content.blur', $content->id))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');

    // Irreversível: baixa resolução (≤ 40px no maior lado) — não dá para reconstruir
    // o original de 800px a partir daqui.
    $size = getimagesizefromstring($res->getContent());
    expect($size)->not->toBeFalse();
    expect(max($size[0], $size[1]))->toBeLessThanOrEqual(40);
});

it('a imagem ORIGINAL continua 404 para quem nao pode ver, mesmo com a previa existindo', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);
    $free = pcMember();

    // A prévia existe e é servida...
    $this->actingAs($free)->get(route('content.blur', $content->id))->assertOk();

    // ...mas a imagem ORIGINAL (bytes reais) segue barrada por canView.
    $this->actingAs($free)->get(route('content.image', $content->id))->assertNotFound();
});

it('o presenter da previa (blur_url) so aparece no tile BLOQUEADO, nunca em quem ja ve', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);

    // Bloqueado (free): blur_url presente, image_url null.
    $free = pcMember();
    $item = App\Support\ContentPresenter::one($content->fresh(), $free);
    expect($item['image_url'])->toBeNull()
        ->and($item['blur_url'])->not->toBeNull();

    // Dona (vê tudo): image_url presente, blur_url null (ela tem a imagem real).
    $ownerItem = App\Support\ContentPresenter::one($content->fresh(), $profile->user);
    expect($ownerItem['image_url'])->not->toBeNull()
        ->and($ownerItem['blur_url'])->toBeNull();
});

it('sem a previa no disco, a rota devolve 404 (o front cai no placeholder)', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);
    $free = pcMember();

    // Apaga a prévia (simula peça antiga, publicada antes da feature).
    $store = app(ContentStore::class);
    $store->delete($store->blurPathFor($content->path));

    $this->actingAs($free)->get(route('content.blur', $content->id))->assertNotFound();
});

it('o comando gera a previa retroativa das pecas que nao tem', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);
    $store = app(ContentStore::class);

    // Remove a prévia gerada no upload → simula peça antiga.
    $store->delete($store->blurPathFor($content->path));
    expect($store->exists($store->blurPathFor($content->path)))->toBeFalse();

    $this->artisan('content:generate-blurs')->assertSuccessful();

    expect($store->exists($store->blurPathFor($content->path)))->toBeTrue();
});
