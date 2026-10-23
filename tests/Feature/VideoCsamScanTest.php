<?php

use App\Jobs\ProcessVideoContent;
use App\Models\CsamHash;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ContentStore;
use App\Services\PerceptualHashService;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Anti-CSAM no VÍDEO (achado da auditoria de segurança, 22/10): o job escaneia o
 * QUADRO DE CAPA antes de publicar. Fecha o ponto cego em que vídeo não passava pelo
 * scanner. Mocka o VideoProcessingService (sem ffmpeg); prefixo `vcs`.
 */
function vcsJpeg(int $seed = 1): string
{
    $img = imagecreatetruecolor(64, 64);
    for ($x = 0; $x < 64; $x++) {
        for ($y = 0; $y < 64; $y++) {
            imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * $seed) % 256, ($y * $seed) % 256, (($x + $y) * $seed) % 256));
        }
    }
    ob_start();
    imagejpeg($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function vcsPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Vcs '.Str::random(5),
        'slug' => 'vcs-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

function vcsVideoRow(PerformerProfile $profile): PerformerContent
{
    $c = new PerformerContent;
    $c->performer_profile_id = $profile->id;
    $c->kind = PerformerContent::KIND_VIDEO;
    $c->status = PerformerContent::STATUS_PROCESSING;
    $c->access_level = 'open';
    $c->price_tokens = 10;
    $c->path = '';
    $c->save();

    return $c;
}

it('bloqueia o vídeo quando o QUADRO DE CAPA bate na lista CSAM (fail-closed)', function () {
    config(['csam.enabled' => true]);
    Storage::fake('performer_content');

    $profile = vcsPerformer();
    $content = vcsVideoRow($profile);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, 'raw-bytes');

    // O poster que o thumbnail vai gerar — semeia o phash dele na lista.
    $poster = vcsJpeg(4);
    CsamHash::create(['hash' => app(PerceptualHashService::class)->hash($poster), 'source' => 'test', 'added_at' => now()]);

    $video = Mockery::mock(VideoProcessingService::class);
    $video->shouldReceive('sanitize')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'SANITIZED-MP4'));
    $video->shouldReceive('thumbnail')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, $poster));
    // probeDurationSeconds/putSanitizedVideo NÃO são alcançados: o scan lança antes.

    (new ProcessVideoContent($content->id, $rawPath))->handle($video, app(ContentStore::class));

    $content->refresh();
    expect($content->status)->toBe('failed')               // não publica
        ->and($content->path)->toBe('')                    // nada gravado
        ->and($content->failure_reason)->not->toBeNull();  // motivo genérico (não revela CSAM)

    // A conta da performer foi sinalizada para review imediato.
    expect($profile->user->fresh()->csam_flagged_at)->not->toBeNull();
    // Nada servível ficou no disco.
    Storage::disk('performer_content')->assertMissing($rawPath);
});

it('publica normalmente quando o quadro de capa NÃO bate na lista', function () {
    config(['csam.enabled' => true]);
    Storage::fake('performer_content');

    $profile = vcsPerformer();
    $content = vcsVideoRow($profile);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk('performer_content')->put($rawPath, 'raw-bytes');

    $poster = vcsJpeg(7); // não está na lista (vazia)

    $video = Mockery::mock(VideoProcessingService::class);
    $video->shouldReceive('sanitize')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'SANITIZED-MP4'));
    $video->shouldReceive('thumbnail')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, $poster));
    $video->shouldReceive('probeDurationSeconds')->once()->andReturn(5.0);

    (new ProcessVideoContent($content->id, $rawPath))->handle($video, app(ContentStore::class));

    $content->refresh();
    expect($content->status)->toBe('ready')
        ->and($content->path)->not->toBe('');
    expect($profile->user->fresh()->csam_flagged_at)->toBeNull();
});
