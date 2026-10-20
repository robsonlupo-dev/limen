<?php

use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerProfileService;
use App\Services\PerformerStoryService;
use App\Services\PerformerStoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

uses(RefreshDatabase::class);

/**
 * Marca de ORIGEM nas demais mídias da performer (Onda B §4, peça 2b): avatar, capa
 * e story reusam o mesmo motor da foto (WatermarkService), respeitando o toggle
 * `origin_mark_handle` e o kill-switch global. Prefixo `omm` (helpers globais no Pest).
 *
 * As asserções de "a marca mudou os bytes" dependem da fonte TTF de verdade (GD),
 * então PULAM quando a fonte não está no ambiente — como o WatermarkTest. A
 * integridade do pipeline (JPEG válido, dimensões certas) é checada SEMPRE.
 */
function ommFontPresent(): bool
{
    return is_file((string) config('custom_order.watermark.font'));
}

function ommPerformer(bool $handle = true): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    static $n = 0;
    $n++;
    $name = 'Mark'.$n;

    return $user->performerProfile()->create([
        'stage_name' => $name,
        'slug' => PerformerProfile::generateSlug($name),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
        'origin_mark_handle' => $handle,
    ]);
}

/** Upload determinístico: a MESMA imagem a cada chamada, para on-vs-off diferir só pela marca. */
function ommUpload(): UploadedFile
{
    $bytes = (string) ImageManager::gd()->create(800, 800)->fill('rgb(40,40,40)')->toJpeg(quality: 85);
    $tmp = tempnam(sys_get_temp_dir(), 'omm').'.jpg';
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, 'src.jpg', 'image/jpeg', null, true);
}

it('story publicado é JPEG válido e, com a marca ligada, os bytes mudam', function () {
    $profile = ommPerformer();

    config()->set('origin_mark.enabled', false);
    $off = app(PerformerStoryService::class)->publish($profile, ommUpload(), 'public');
    $bytesOff = app(PerformerStoryStore::class)->retrieve($off->media_path);
    expect(getimagesizefromstring($bytesOff)[2])->toBe(IMAGETYPE_JPEG);

    if (! ommFontPresent()) {
        $this->markTestSkipped('Fonte da marca ausente neste ambiente.');
    }

    config()->set('origin_mark.enabled', true);
    $on = app(PerformerStoryService::class)->publish($profile, ommUpload(), 'public');
    $bytesOn = app(PerformerStoryStore::class)->retrieve($on->media_path);

    expect(getimagesizefromstring($bytesOn)[2])->toBe(IMAGETYPE_JPEG)
        ->and($bytesOn)->not->toBe($bytesOff); // marca queimada
});

it('avatar é 512×512 e, com a marca ligada, os bytes mudam', function () {
    $svc = app(PerformerProfileService::class);

    config()->set('origin_mark.enabled', false);
    $p1 = ommPerformer();
    $path1 = $svc->replaceAvatar($p1, ommUpload());
    $off = Storage::disk('local')->get($path1);
    $size = getimagesizefromstring($off);
    expect($size[0])->toBe(512)->and($size[1])->toBe(512)->and($size[2])->toBe(IMAGETYPE_JPEG);

    if (! ommFontPresent()) {
        $this->markTestSkipped('Fonte da marca ausente neste ambiente.');
    }

    config()->set('origin_mark.enabled', true);
    $p2 = ommPerformer();
    $path2 = $svc->replaceAvatar($p2, ommUpload());
    $on = Storage::disk('local')->get($path2);

    expect(getimagesizefromstring($on)[2])->toBe(IMAGETYPE_JPEG)
        ->and($on)->not->toBe($off);
});

it('capa é 1200×400 e, com a marca ligada, os bytes mudam', function () {
    $svc = app(PerformerProfileService::class);

    config()->set('origin_mark.enabled', false);
    $p1 = ommPerformer();
    $path1 = $svc->replaceCover($p1, ommUpload());
    $off = Storage::disk('local')->get($path1);
    $size = getimagesizefromstring($off);
    expect($size[0])->toBe(1200)->and($size[1])->toBe(400)->and($size[2])->toBe(IMAGETYPE_JPEG);

    if (! ommFontPresent()) {
        $this->markTestSkipped('Fonte da marca ausente neste ambiente.');
    }

    config()->set('origin_mark.enabled', true);
    $p2 = ommPerformer();
    $path2 = $svc->replaceCover($p2, ommUpload());
    $on = Storage::disk('local')->get($path2);

    expect(getimagesizefromstring($on)[2])->toBe(IMAGETYPE_JPEG)
        ->and($on)->not->toBe($off);
});

it('com o toggle do @ desligado, o story ainda publica (texto só "Limen")', function () {
    $profile = ommPerformer(handle: false);
    config()->set('origin_mark.enabled', true);

    $story = app(PerformerStoryService::class)->publish($profile, ommUpload(), 'public');
    $bytes = app(PerformerStoryStore::class)->retrieve($story->media_path);

    expect(getimagesizefromstring($bytes)[2])->toBe(IMAGETYPE_JPEG);
});
