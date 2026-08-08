<?php

use App\Exceptions\VideoProcessingException;
use App\Jobs\ProcessVideoContent;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ContentStore;
use App\Services\ContentVisibilityService;
use App\Services\PerformerContentService;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Sanitização de vídeo (Sprint 16). O ffmpeg de verdade é EXERCITADO por um teste
// guardado (pula sem ffmpeg — ex.: CI); os demais MOCKAM o VideoProcessingService
// para cobrir a orquestração/gating sem depender do binário. Helpers locais (vid*).

function vidPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Vid '.Str::random(5),
        'slug' => 'vid-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function vidProcessingRow(PerformerProfile $profile, string $level = 'open'): PerformerContent
{
    $c = new PerformerContent;
    $c->performer_profile_id = $profile->id;
    $c->kind = PerformerContent::KIND_VIDEO;
    $c->status = PerformerContent::STATUS_PROCESSING;
    $c->access_level = $level;
    $c->price_tokens = 10;
    $c->path = '';
    $c->save();

    return $c;
}

function vidUpload(): UploadedFile
{
    // Fixture REAL (finfo detecta video/mp4) — sem isso o mimetypes/getMimeType
    // não reconheceria o upload como vídeo. Modo teste (copia, não move).
    return new UploadedFile(base_path('tests/fixtures/sample.mp4'), 'sample.mp4', 'video/mp4', null, true);
}

// ─── publishVideo: cria processing + despacha o job ──────────────────────────

it('publishVideo cria peça em processing e despacha o job de sanitização', function () {
    Queue::fake();
    Storage::fake('performer_content');
    $this->mock(VideoProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->once()->andReturn(5.0));

    $content = app(PerformerContentService::class)->publishVideo(vidPerformer(), vidUpload(), 'open', 10);

    expect($content->kind)->toBe('video')
        ->and($content->status)->toBe('processing')
        ->and($content->path)->toBe('');
    Queue::assertPushed(ProcessVideoContent::class);
});

it('recusa vídeo acima da duração máxima (too_long) antes de despachar', function () {
    Queue::fake();
    Storage::fake('performer_content');
    $this->mock(VideoProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andReturn(700.0)); // > 600

    try {
        app(PerformerContentService::class)->publishVideo(vidPerformer(), vidUpload(), 'open', 10);
        $this->fail('deveria ter lançado');
    } catch (VideoProcessingException $e) {
        expect($e->reason)->toBe(VideoProcessingException::TOO_LONG);
    }

    expect(PerformerContent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('propaga ffmpeg indisponível (ffmpeg_missing) como erro de upload', function () {
    Storage::fake('performer_content');
    $this->mock(VideoProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andThrow(VideoProcessingException::ffmpegMissing()));

    try {
        app(PerformerContentService::class)->publishVideo(vidPerformer(), vidUpload(), 'open', 10);
        $this->fail('deveria ter lançado');
    } catch (VideoProcessingException $e) {
        expect($e->reason)->toBe(VideoProcessingException::FFMPEG_MISSING);
    }
});

// ─── Job: sucesso e falha ────────────────────────────────────────────────────

it('o job sanitiza, grava vídeo + thumbnail e marca a peça como ready', function () {
    Storage::fake('performer_content');
    $profile = vidPerformer();
    $content = vidProcessingRow($profile);

    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, 'raw-bytes');

    $video = Mockery::mock(VideoProcessingService::class);
    $video->shouldReceive('sanitize')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'SANITIZED-MP4'));
    $video->shouldReceive('thumbnail')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'POSTER-JPG'));
    $video->shouldReceive('probeDurationSeconds')->once()->andReturn(5.0);

    (new ProcessVideoContent($content->id, $rawPath))->handle($video, app(ContentStore::class));

    $content->refresh();
    expect($content->status)->toBe('ready')
        ->and($content->path)->not->toBe('')
        ->and($content->thumbnail_path)->not->toBeNull()
        ->and($content->duration_seconds)->toBe(5)
        ->and($content->content_hash)->not->toBeNull();

    Storage::disk('performer_content')->assertExists($content->path);
    Storage::disk('performer_content')->assertExists($content->thumbnail_path);
    // O cru foi limpo.
    Storage::disk('performer_content')->assertMissing($rawPath);
});

it('o job marca a peça como failed com mensagem quando o ffmpeg falha', function () {
    Storage::fake('performer_content');
    $profile = vidPerformer();
    $content = vidProcessingRow($profile);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, 'raw-bytes');

    $video = Mockery::mock(VideoProcessingService::class);
    $video->shouldReceive('sanitize')->once()->andThrow(VideoProcessingException::encodeFailed());

    (new ProcessVideoContent($content->id, $rawPath))->handle($video, app(ContentStore::class));

    $content->refresh();
    expect($content->status)->toBe('failed')
        ->and($content->failure_reason)->not->toBeNull();
    Storage::disk('performer_content')->assertMissing($rawPath);
});

// ─── Gating: só READY é servível/visível ─────────────────────────────────────

it('vídeo em processing não é servível nem aparece na galeria', function () {
    $profile = vidPerformer();
    $content = vidProcessingRow($profile);
    $vis = app(ContentVisibilityService::class);
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    // Nem membro, nem visitante, nem a PRÓPRIA dona veem bytes que não existem.
    expect($vis->canView($member, $content))->toBeFalse()
        ->and($vis->canView(null, $content))->toBeFalse()
        ->and($vis->canView($profile->user, $content))->toBeFalse()
        // Fora da vitrine (ready scope).
        ->and($vis->galleryFor($member, $profile))->toBe([]);
});

// ─── ffmpeg REAL (pula sem o binário — ex.: CI) ──────────────────────────────

it('sanitiza um vídeo REAL de ponta a ponta com ffmpeg', function () {
    try {
        app(VideoProcessingService::class)->assertAvailable();
    } catch (VideoProcessingException) {
        test()->markTestSkipped('ffmpeg indisponível neste ambiente.');
    }

    Storage::fake('performer_content');
    $profile = vidPerformer();
    $content = vidProcessingRow($profile);

    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, file_get_contents(base_path('tests/fixtures/sample.mp4')));

    (new ProcessVideoContent($content->id, $rawPath))->handle(
        app(VideoProcessingService::class),
        app(ContentStore::class),
    );

    $content->refresh();
    expect($content->status)->toBe('ready')
        ->and($content->duration_seconds)->toBeGreaterThanOrEqual(1);

    // O arquivo servido é um MP4 de verdade (re-encodado), com poster JPEG.
    $videoBytes = Storage::disk('performer_content')->get($content->path);
    $thumbBytes = Storage::disk('performer_content')->get($content->thumbnail_path);
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($videoBytes))->toContain('video')
        ->and((new finfo(FILEINFO_MIME_TYPE))->buffer($thumbBytes))->toContain('image');
});

it('gera o poster mesmo quando o segundo do thumbnail passa do fim (fallback frame 0)', function () {
    try {
        app(VideoProcessingService::class)->assertAvailable();
    } catch (VideoProcessingException) {
        test()->markTestSkipped('ffmpeg indisponível neste ambiente.');
    }

    // Segundo além do fim do vídeo (fixture ~3s) → 1ª tentativa vazia → fallback 0.
    config(['video.thumbnail_second' => 999]);

    Storage::fake('performer_content');
    $profile = vidPerformer();
    $content = vidProcessingRow($profile);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, file_get_contents(base_path('tests/fixtures/sample.mp4')));

    (new ProcessVideoContent($content->id, $rawPath))->handle(app(VideoProcessingService::class), app(ContentStore::class));

    $content->refresh();
    expect($content->status)->toBe('ready')
        ->and($content->thumbnail_path)->not->toBeNull();
});

// ─── GC dos crus órfãos ──────────────────────────────────────────────────────

it('content:purge-orphan-raw apaga crus órfãos e poupa os recentes', function () {
    Storage::fake('performer_content');
    $disk = Storage::disk('performer_content');
    $disk->put('tmp/1/old', 'x');
    $disk->put('tmp/1/fresh', 'y');
    // Envelhece o "old" para além de 2× o timeout do job.
    touch($disk->path('tmp/1/old'), now()->getTimestamp() - (config('video.process_timeout') * 2) - 100);

    $this->artisan('content:purge-orphan-raw')->assertSuccessful();

    $disk->assertMissing('tmp/1/old')->assertExists('tmp/1/fresh');
});

it('content:purge-orphan-raw não estoura quando não há diretório tmp/', function () {
    Storage::fake('performer_content');

    $this->artisan('content:purge-orphan-raw')->assertSuccessful();
});
