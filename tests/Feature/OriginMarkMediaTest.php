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
    return ommUploadSized(800, 800);
}

function ommUploadSized(int $w, int $h): UploadedFile
{
    $bytes = (string) ImageManager::gd()->create($w, $h)->fill('rgb(40,40,40)')->toJpeg(quality: 85);
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

it('capa é 1200×600 (2:1) e, com a marca ligada, os bytes mudam', function () {
    $svc = app(PerformerProfileService::class);

    config()->set('origin_mark.enabled', false);
    $p1 = ommPerformer();
    $path1 = $svc->replaceCover($p1, ommUpload());
    $off = Storage::disk('local')->get($path1);
    $size = getimagesizefromstring($off);
    expect($size[0])->toBe(1200)->and($size[1])->toBe(600)->and($size[2])->toBe(IMAGETYPE_JPEG);

    // A cópia INTEIRA (UAT rodada 5): gravada, NÃO recortada para 2:1 — a fonte
    // 800×800 sai 800×800 (scaleDown nunca amplia), distinta do banner 1200×600.
    $fullPath = $p1->fresh()->cover_full_path;
    expect($fullPath)->not->toBeNull()
        ->and(Storage::disk('local')->exists($fullPath))->toBeTrue();
    $fullSize = getimagesizefromstring(Storage::disk('local')->get($fullPath));
    expect($fullSize[0])->toBe(800)->and($fullSize[1])->toBe(800)->and($fullSize[2])->toBe(IMAGETYPE_JPEG);

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

it('usa o ORIGINAL (não o recorte do cliente) para a cópia inteira da capa', function () {
    $svc = app(PerformerProfileService::class);
    config()->set('origin_mark.enabled', false);
    $p = ommPerformer();

    // O cliente corta a 2:1 antes de subir → `file` é a faixa (800×800 aqui);
    // o ORIGINAL retrato vai como segundo arg.
    $cropped = ommUploadSized(800, 800);
    $original = ommUploadSized(600, 1600);
    $svc->replaceCover($p, $cropped, $original);

    // Banner = 1200×600 (recorte forçado).
    $cover = getimagesizefromstring(Storage::disk('local')->get($p->fresh()->cover_path));
    expect($cover[0])->toBe(1200)->and($cover[1])->toBe(600);

    // Cópia inteira = o ORIGINAL reduzido (600×1600 cabe em 1600 → 600×1600),
    // NÃO o recorte 2:1 nem os 800×800 da faixa. É a prova do fix da rodada 6.
    $full = getimagesizefromstring(Storage::disk('local')->get($p->fresh()->cover_full_path));
    expect($full[0])->toBe(600)->and($full[1])->toBe(1600);
});

it('serve a capa INTEIRA pela rota de mídia assinada (type cover-full) e 404 em type inválido', function () {
    $svc = app(PerformerProfileService::class);
    config()->set('origin_mark.enabled', false);
    $p = ommPerformer();
    $svc->replaceCover($p, ommUpload());

    $ok = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'performer.media', now()->addMinutes(5), ['profile_id' => $p->id, 'type' => 'cover-full']
    );
    test()->get($ok)->assertOk();

    // Allowlist de type: qualquer coisa fora de avatar/cover/cover-full → 404.
    $bad = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'performer.media', now()->addMinutes(5), ['profile_id' => $p->id, 'type' => 'nope']
    );
    test()->get($bad)->assertNotFound();
});

it('com o toggle do @ desligado, o story ainda publica (texto só "Limen")', function () {
    $profile = ommPerformer(handle: false);
    config()->set('origin_mark.enabled', true);

    $story = app(PerformerStoryService::class)->publish($profile, ommUpload(), 'public');
    $bytes = app(PerformerStoryStore::class)->retrieve($story->media_path);

    expect(getimagesizefromstring($bytes)[2])->toBe(IMAGETYPE_JPEG);
});

it('foto da GALERIA é JPEG válido e, com a marca ligada, os bytes mudam (achado do UAT)', function () {
    $svc = app(App\Services\PerformerPhotoService::class);

    config()->set('origin_mark.enabled', false);
    $p1 = ommPerformer();
    $off = Storage::disk(App\Services\PerformerPhotoStore::DISK)->get($svc->add($p1, ommUpload())->path);
    expect(getimagesizefromstring($off)[2])->toBe(IMAGETYPE_JPEG);

    if (! ommFontPresent()) {
        $this->markTestSkipped('Fonte da marca ausente neste ambiente.');
    }

    config()->set('origin_mark.enabled', true);
    $p2 = ommPerformer();
    $on = Storage::disk(App\Services\PerformerPhotoStore::DISK)->get($svc->add($p2, ommUpload())->path);

    expect(getimagesizefromstring($on)[2])->toBe(IMAGETYPE_JPEG)
        ->and($on)->not->toBe($off); // marca queimada na galeria
});
