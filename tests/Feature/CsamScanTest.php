<?php

use App\Exceptions\CsamDetectedException;
use App\Models\ContentHashCheck;
use App\Models\CsamHash;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\CsamScanService;
use App\Services\PerceptualHashService;
use App\Services\PerformerContentService;
use App\Services\PerformerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Anti-CSAM MVP (Sprint 16). phash de toda imagem no upload, conferido contra a
// lista local. Match → bloqueia + CRITICAL + sinaliza a conta; hasher indisponível
// → fail-open (WARNING). Helpers locais (csam*).

function csamBytes(int $w = 64, int $h = 64): string
{
    // JPEG determinístico via GD (gradiente) — bytes reais e estáveis, sem temp
    // de UploadedFile (que some antes da leitura).
    $img = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 4) % 256, ($y * 4) % 256, (($x + $y) * 3) % 256));
        }
    }
    ob_start();
    imagejpeg($img);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function csamPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Csam '.Str::random(5),
        'slug' => 'csam-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ]);
}

// ─── phash ───────────────────────────────────────────────────────────────────

it('o phash é 16 hex e estável para a mesma imagem', function () {
    $bytes = csamBytes();
    $hasher = app(PerceptualHashService::class);

    expect($hasher->hash($bytes))->toBe($hasher->hash($bytes))
        ->and($hasher->hash($bytes))->toMatch('/^[0-9a-f]{16}$/');
});

// ─── Scan: limpo, match, fail-open, desligado ────────────────────────────────

it('registra a verificação e NÃO bloqueia imagem limpa (lista vazia)', function () {
    $user = User::factory()->create();

    app(CsamScanService::class)->scanBytes(csamBytes(), 'content', $user); // não lança

    $check = ContentHashCheck::sole();
    expect($check->matched)->toBeFalse()
        ->and($check->verified)->toBeTrue()
        ->and($check->hash)->not->toBeNull()
        ->and($check->context)->toBe('content')
        ->and($check->user_id)->toBe($user->id);
    expect($user->fresh()->csam_flagged_at)->toBeNull();
});

it('bloqueia, loga CRITICAL e sinaliza a conta quando o phash bate na lista', function () {
    Log::spy();
    $bytes = csamBytes();
    CsamHash::create(['hash' => app(PerceptualHashService::class)->hash($bytes), 'source' => 'test', 'added_at' => now()]);
    $user = User::factory()->create();

    expect(fn () => app(CsamScanService::class)->scanBytes($bytes, 'avatar', $user))
        ->toThrow(CsamDetectedException::class);

    $check = ContentHashCheck::where('matched', true)->sole();
    expect($check->context)->toBe('avatar')->and($check->verified)->toBeTrue();
    expect($user->fresh()->csam_flagged_at)->not->toBeNull();
    Log::shouldHaveReceived('critical')->with('csam.match', Mockery::type('array'))->once();
});

it('faz FAIL-OPEN (não bloqueia, WARNING) quando o hash não pode ser computado', function () {
    Log::spy();
    $user = User::factory()->create();

    app(CsamScanService::class)->scanBytes('not-an-image', 'content', $user); // não lança

    $check = ContentHashCheck::sole();
    expect($check->verified)->toBeFalse()
        ->and($check->matched)->toBeFalse()
        ->and($check->hash)->toBeNull();
    expect($user->fresh()->csam_flagged_at)->toBeNull();
    Log::shouldHaveReceived('warning')->with('csam.unverified', Mockery::type('array'))->once();
});

it('não verifica nada quando a checagem está desligada', function () {
    config(['csam.enabled' => false]);

    app(CsamScanService::class)->scanBytes(csamBytes(), 'content', User::factory()->create());

    expect(ContentHashCheck::count())->toBe(0);
});

// ─── Wiring: os uploads passam pelo scan e o bloqueio impede a gravação ───────

it('a publicação de conteúdo propaga o bloqueio e não grava bytes nem linha', function () {
    Storage::fake('performer_content');
    $this->mock(CsamScanService::class, fn ($m) => $m->shouldReceive('scanBytes')->once()->andThrow(new CsamDetectedException));

    $profile = csamPerformer();
    $file = UploadedFile::fake()->image('x.jpg', 120, 120);

    expect(fn () => app(PerformerContentService::class)->publish($profile, $file, 'open', 10))
        ->toThrow(CsamDetectedException::class);

    expect(PerformerContent::count())->toBe(0)
        ->and(Storage::disk('performer_content')->allFiles())->toBeEmpty();
});

it('replaceAvatar bloqueia sem destruir o avatar anterior nem gravar o novo', function () {
    Storage::fake('local');
    $profile = csamPerformer();
    $existing = "performer-media/{$profile->user_id}/avatar.jpg";
    Storage::disk('local')->put($existing, 'OLD-AVATAR');
    $profile->update(['avatar_path' => $existing]);

    $this->mock(CsamScanService::class, fn ($m) => $m->shouldReceive('scanBytes')->once()->andThrow(new CsamDetectedException));

    expect(fn () => app(PerformerProfileService::class)->replaceAvatar($profile, UploadedFile::fake()->image('x.jpg', 100, 100)))
        ->toThrow(CsamDetectedException::class);

    // O avatar anterior sobrevive ao upload bloqueado.
    expect(Storage::disk('local')->get($existing))->toBe('OLD-AVATAR');
});

it('escaneia também story, galeria e foto efêmera com o contexto certo', function () {
    Storage::fake('performer_stories');
    Storage::fake('performer_photos');
    Storage::fake('member_photos');

    $spy = Mockery::spy(CsamScanService::class);
    app()->instance(CsamScanService::class, $spy);

    $profile = csamPerformer();

    app(App\Services\PerformerStoryStore::class)->store(UploadedFile::fake()->image('s.jpg', 120, 120), $profile->id, $profile->user);
    app(App\Services\PerformerPhotoStore::class)->store(UploadedFile::fake()->image('g.jpg', 120, 120), $profile->id, $profile->user);
    app(App\Services\MemberPhotoStore::class)->store(UploadedFile::fake()->image('m.jpg', 120, 120), $profile->user_id, $profile->user);

    foreach (['story', 'gallery', 'member_photo'] as $context) {
        $spy->shouldHaveReceived('scanBytes')
            ->withArgs(fn ($bytes, $ctx) => $ctx === $context)
            ->once();
    }
});

// ─── Import ──────────────────────────────────────────────────────────────────

it('csam:import-hashes importa do CSV e é idempotente', function () {
    $csv = tempnam(sys_get_temp_dir(), 'csam').'.csv';
    file_put_contents($csv, "hash,source\nabc123def4567890,ncmec\nfedcba0987654321,iwf\n");

    $this->artisan('csam:import-hashes', ['file' => $csv])->assertSuccessful();
    expect(CsamHash::count())->toBe(2)
        ->and(CsamHash::where('hash', 'abc123def4567890')->first()->source)->toBe('ncmec');

    // Reimportar não duplica.
    $this->artisan('csam:import-hashes', ['file' => $csv])->assertSuccessful();
    expect(CsamHash::count())->toBe(2);

    @unlink($csv);
});
