<?php

use App\Exceptions\VoiceProcessingException;
use App\Jobs\ProcessVoiceIntro;
use App\Jobs\SendVoiceIntroApprovedEmail;
use App\Jobs\SendVoiceIntroFailedEmail;
use App\Jobs\SendVoiceIntroRejectedEmail;
use App\Models\PerformerProfile;
use App\Models\PerformerVoiceIntro;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\PerformerVoiceIntroService;
use App\Services\VoiceIntroStore;
use App\Services\VoiceProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Intro de voz da performer (feat/voice-intro). O ffmpeg de verdade é EXERCITADO
// por testes guardados (pulam sem ffmpeg); os demais MOCKAM o VoiceProcessingService
// para cobrir orquestração/gating/moderação sem depender do binário. Helpers (vi*).

function voicePerformer(bool $verified = true, string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create([
        'stage_name' => 'Voz '.Str::random(5),
        'slug' => 'voz-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $verified,
    ]);
}

function voiceModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active']);
}

function voiceUpload(string $fixture = 'sample-voice.mp3', string $mime = 'audio/mpeg'): UploadedFile
{
    return new UploadedFile(base_path("tests/fixtures/{$fixture}"), $fixture, $mime, null, true);
}

/** Uma intro pronta no disco fake, no status pedido. */
function voiceIntroRow(PerformerProfile $profile, string $status, bool $withBytes = true): PerformerVoiceIntro
{
    $path = '';
    if ($withBytes) {
        $path = $profile->id.'/'.Str::random(20).'.mp3';
        Storage::disk(VoiceIntroStore::DISK)->put($path, 'MP3-BYTES');
    }

    $intro = new PerformerVoiceIntro;
    $intro->performer_profile_id = $profile->id;
    $intro->status = $status;
    $intro->path = $path;
    $intro->duration_seconds = $withBytes ? 12 : null;
    $intro->save();

    return $intro;
}

// ─── upload: cria processing + despacha o job ────────────────────────────────

it('upload cria intro em processing e despacha o job de sanitização', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $this->mock(VoiceProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->once()->andReturn(8.0));

    $intro = app(PerformerVoiceIntroService::class)->upload(voicePerformer(), voiceUpload());

    expect($intro->status)->toBe('processing')->and($intro->path)->toBe('');
    Queue::assertPushed(ProcessVoiceIntro::class);
});

it('recusa áudio acima da duração máxima (too_long) antes de despachar', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $this->mock(VoiceProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andReturn(25.0)); // > 20

    expect(fn () => app(PerformerVoiceIntroService::class)->upload(voicePerformer(), voiceUpload()))
        ->toThrow(VoiceProcessingException::class);

    expect(PerformerVoiceIntro::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('defere o gate de duração quando o container não traz duração (webm), sem barrar no upload', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    // null = duração indeterminada no header → NÃO barra aqui, o job reconfere.
    $this->mock(VoiceProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andReturn(null));

    $intro = app(PerformerVoiceIntroService::class)->upload(voicePerformer(), voiceUpload());

    expect($intro->status)->toBe('processing');
    Queue::assertPushed(ProcessVoiceIntro::class);
});

it('propaga ffmpeg indisponível (ffmpeg_missing) como erro de upload — fail-closed', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $this->mock(VoiceProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andThrow(VoiceProcessingException::ffmpegMissing()));

    try {
        app(PerformerVoiceIntroService::class)->upload(voicePerformer(), voiceUpload());
        $this->fail('deveria ter lançado');
    } catch (VoiceProcessingException $e) {
        expect($e->reason)->toBe(VoiceProcessingException::FFMPEG_MISSING);
    }
    expect(PerformerVoiceIntro::count())->toBe(0);
});

it('regravar SUBSTITUI a intro anterior (uma por performer) e apaga os bytes antigos', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $this->mock(VoiceProcessingService::class, fn ($m) => $m->shouldReceive('probeDurationSeconds')->andReturn(8.0));

    $profile = voicePerformer();
    $old = voiceIntroRow($profile, 'approved');
    $oldPath = $old->path;

    app(PerformerVoiceIntroService::class)->upload($profile, voiceUpload());

    expect(PerformerVoiceIntro::where('performer_profile_id', $profile->id)->count())->toBe(1)
        ->and(PerformerVoiceIntro::find($old->id))->toBeNull();
    Storage::disk(VoiceIntroStore::DISK)->assertMissing($oldPath);
});

// ─── Job: sucesso vai para PENDING (não approved), falhas → failed ────────────

it('o job sanitiza, grava o MP3 e marca a intro como PENDING (aguardando moderação), nunca approved', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'processing', withBytes: false);

    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk(VoiceIntroStore::DISK)->put($rawPath, 'raw-bytes');

    $voice = Mockery::mock(VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andReturnUsing(fn ($src, $dest) => file_put_contents($dest, 'SANITIZED-MP3'));
    $voice->shouldReceive('probeDurationSeconds')->once()->andReturn(9.0);

    (new ProcessVoiceIntro($intro->id, $rawPath))->handle($voice, app(VoiceIntroStore::class));

    $intro->refresh();
    expect($intro->status)->toBe('pending')            // <- NUNCA approved sozinho
        ->and($intro->path)->not->toBe('')
        ->and($intro->duration_seconds)->toBe(9)
        ->and($intro->content_hash)->not->toBeNull();
    Storage::disk(VoiceIntroStore::DISK)->assertExists($intro->path)->assertMissing($rawPath);
});

it('o job marca FAILED quando o ffmpeg falha', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'processing', withBytes: false);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk(VoiceIntroStore::DISK)->put($rawPath, 'raw');

    $voice = Mockery::mock(VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andThrow(VoiceProcessingException::encodeFailed());

    (new ProcessVoiceIntro($intro->id, $rawPath))->handle($voice, app(VoiceIntroStore::class));

    $intro->refresh();
    expect($intro->status)->toBe('failed')->and($intro->reject_reason)->not->toBeNull();
    Storage::disk(VoiceIntroStore::DISK)->assertMissing($rawPath);
});

it('o job REJEITA (failed too_long) quando o MP3 processado excede o teto — não trunca', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'processing', withBytes: false);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk(VoiceIntroStore::DISK)->put($rawPath, 'raw');

    $voice = Mockery::mock(VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andReturnUsing(fn ($s, $d) => file_put_contents($d, 'MP3'));
    $voice->shouldReceive('probeDurationSeconds')->once()->andReturn(30.0); // > 20

    (new ProcessVoiceIntro($intro->id, $rawPath))->handle($voice, app(VoiceIntroStore::class));

    expect($intro->refresh()->status)->toBe('failed');
});

// ─── Serving público: SÓ approved, SÓ performer de pé ─────────────────────────

it('serve publicamente (inclusive a visitante deslogado) APENAS a intro aprovada', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    voiceIntroRow($profile, 'approved');

    // Sem actingAs — é isca pública, o visitante deslogado ouve.
    $this->get(route('voice-intro.audio', $profile))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('NÃO serve intro pending/processing/rejected/failed (404) — a moderação é o gate', function (string $status) {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    voiceIntroRow($profile, $status);

    $this->get(route('voice-intro.audio', $profile))->assertNotFound();
})->with(['pending', 'processing', 'rejected', 'failed']);

it('NÃO serve intro aprovada de performer fora do ar (não verificada / suspensa)', function () {
    Storage::fake(VoiceIntroStore::DISK);

    $unverified = voicePerformer(verified: false);
    voiceIntroRow($unverified, 'approved');
    $this->get(route('voice-intro.audio', $unverified))->assertNotFound();

    $suspended = voicePerformer(status: 'suspended');
    voiceIntroRow($suspended, 'approved');
    $this->get(route('voice-intro.audio', $suspended))->assertNotFound();
});

// ─── has_voice_intro / voice_intro_url ────────────────────────────────────────

it('has_voice_intro/URL: falso e null sem intro aprovada, verdadeiro e presente com ela', function () {
    Storage::fake(VoiceIntroStore::DISK);

    // Sem intro: falso, sem URL no perfil público.
    $none = voicePerformer();
    expect($none->fresh()->hasApprovedVoiceIntro())->toBeFalse();
    $this->get(route('performers.public.show', $none->slug))
        ->assertInertia(fn ($p) => $p->where('performer.has_voice_intro', false)->where('performer.voice_intro_url', null));

    // Intro pending (uma por performer, mas outra performer): ainda falso.
    $waiting = voicePerformer();
    voiceIntroRow($waiting, 'pending');
    expect($waiting->fresh()->hasApprovedVoiceIntro())->toBeFalse();

    // Intro aprovada: verdadeiro + URL presente.
    $live = voicePerformer();
    voiceIntroRow($live, 'approved');
    expect($live->fresh()->hasApprovedVoiceIntro())->toBeTrue();
    $this->get(route('performers.public.show', $live->slug))
        ->assertInertia(fn ($p) => $p->where('performer.has_voice_intro', true)->whereNot('performer.voice_intro_url', null));
});

// ─── Gates de role: quem grava, quem modera ───────────────────────────────────

it('só performer alcança as rotas de gestão; consumer leva 403', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)->get(route('performer.voice-intro.edit'))->assertForbidden();
    $this->actingAs($consumer)->post(route('performer.voice-intro.store'), ['audio' => voiceUpload()])->assertForbidden();
});

it('só moderador/admin alcança a fila de moderação; consumer/performer levam 403', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $performer = voicePerformer();

    $this->actingAs($consumer)->get(route('moderacao.voice-intros.index'))->assertForbidden();
    $this->actingAs($performer->user)->get(route('moderacao.voice-intros.index'))->assertForbidden();
    $this->actingAs(voiceModerator())->get(route('moderacao.voice-intros.index'))->assertOk();
});

// ─── Moderação: aprovar / recusar ─────────────────────────────────────────────

it('a fila lista os pendentes; aprovar publica, recusar guarda o motivo', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'pending');
    $mod = voiceModerator();

    // Fila mostra a pendente.
    $this->actingAs($mod)->get(route('moderacao.voice-intros.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Moderacao/VoiceIntros/Index')->where('pendingCount', 1));

    // Aprovar.
    $this->actingAs($mod)->patch(route('moderacao.voice-intros.update', $intro->id), ['status' => 'approved'])
        ->assertRedirect();
    $intro->refresh();
    expect($intro->status)->toBe('approved')->and($intro->moderated_by)->toBe($mod->id);
});

it('recusar EXIGE motivo e o grava para a performer', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'pending');
    $mod = voiceModerator();

    // Sem motivo → 422.
    $this->actingAs($mod)->from(route('moderacao.voice-intros.index'))
        ->patch(route('moderacao.voice-intros.update', $intro->id), ['status' => 'rejected'])
        ->assertSessionHasErrors('reject_reason');

    // Com motivo → recusa.
    $this->actingAs($mod)->patch(route('moderacao.voice-intros.update', $intro->id), [
        'status' => 'rejected', 'reject_reason' => 'Cita contato externo.',
    ])->assertRedirect();

    $intro->refresh();
    expect($intro->status)->toBe('rejected')->and($intro->reject_reason)->toBe('Cita contato externo.');
});

it('a moderação é no-op sobre uma intro que não está pending (idempotência de estado)', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'approved');

    app(PerformerVoiceIntroService::class)->reject($intro, voiceModerator(), 'tarde demais');
    expect($intro->fresh()->status)->toBe('approved'); // não regride
});

// ─── Notificação à performer na mudança de status (feat/voice-intro-polish) ────

it('aprovar NOTIFICA a performer que a voz está no ar', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'pending');

    app(PerformerVoiceIntroService::class)->approve($intro, voiceModerator());

    Queue::assertPushed(SendVoiceIntroApprovedEmail::class, fn ($job) => $job->user->is($profile->user));
    Queue::assertNotPushed(SendVoiceIntroRejectedEmail::class);
});

it('recusar NOTIFICA a performer com o motivo e o convite a regravar', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'pending');

    app(PerformerVoiceIntroService::class)->reject($intro, voiceModerator(), 'Cita contato externo.');

    Queue::assertPushed(SendVoiceIntroRejectedEmail::class, fn ($job) => $job->user->is($profile->user) && $job->reason === 'Cita contato externo.');
    Queue::assertNotPushed(SendVoiceIntroApprovedEmail::class);
});

it('a moderação no-op (não pending) NÃO notifica a performer', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $intro = voiceIntroRow(voicePerformer(), 'approved');

    app(PerformerVoiceIntroService::class)->reject($intro, voiceModerator(), 'tarde demais');

    Queue::assertNotPushed(SendVoiceIntroRejectedEmail::class);
});

it('a FALHA técnica do job NOTIFICA a performer (mensagem distinta de recusa)', function () {
    Queue::fake();
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'processing', withBytes: false);
    $rawPath = 'tmp/'.$profile->id.'/'.Str::random(10);
    Storage::disk(VoiceIntroStore::DISK)->put($rawPath, 'raw');

    $voice = Mockery::mock(VoiceProcessingService::class);
    $voice->shouldReceive('sanitize')->once()->andThrow(VoiceProcessingException::encodeFailed());

    (new ProcessVoiceIntro($intro->id, $rawPath))->handle($voice, app(VoiceIntroStore::class));

    Queue::assertPushed(SendVoiceIntroFailedEmail::class, fn ($job) => $job->user->is($profile->user));
    // Falha técnica NÃO é recusa de conteúdo — não dispara o e-mail de recusa.
    Queue::assertNotPushed(SendVoiceIntroRejectedEmail::class);
});

it('o moderador OUVE o áudio pendente pelo endpoint dedicado', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $intro = voiceIntroRow(voicePerformer(), 'pending');

    $this->actingAs(voiceModerator())->get(route('moderacao.voice-intros.audio', $intro->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});

// ─── Hard Delete ──────────────────────────────────────────────────────────────

it('o Hard Delete varre a intro (linha + bytes)', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $profile = voicePerformer();
    $intro = voiceIntroRow($profile, 'approved');
    $path = $intro->path;

    app(DeletionService::class)->executeDeletion($profile->user);

    expect(PerformerVoiceIntro::where('performer_profile_id', $profile->id)->count())->toBe(0);
    Storage::disk(VoiceIntroStore::DISK)->assertMissing($path);
});

// ─── GC dos crus órfãos ───────────────────────────────────────────────────────

it('voice:purge-orphan-raw apaga crus órfãos e poupa os recentes', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $disk = Storage::disk(VoiceIntroStore::DISK);
    $disk->put('tmp/1/old', 'x');
    $disk->put('tmp/1/fresh', 'y');
    touch($disk->path('tmp/1/old'), now()->getTimestamp() - (config('voice.process_timeout') * 2) - 100);

    $this->artisan('voice:purge-orphan-raw')->assertSuccessful();

    $disk->assertMissing('tmp/1/old')->assertExists('tmp/1/fresh');
});

it('voice:purge-orphan-raw não estoura sem diretório tmp/', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $this->artisan('voice:purge-orphan-raw')->assertSuccessful();
});

// ─── Upload HTTP: valida tipo e tamanho ───────────────────────────────────────

it('rejeita upload que não é áudio (422)', function () {
    Storage::fake(VoiceIntroStore::DISK);
    $performer = voicePerformer();

    // sample.mp4 é video/mp4 — fora do allowlist de áudio (só video/webm entra).
    $this->actingAs($performer->user)
        ->post(route('performer.voice-intro.store'), [
            'audio' => new UploadedFile(base_path('tests/fixtures/sample.mp4'), 'x.mp4', 'video/mp4', null, true),
        ])
        ->assertStatus(422);
});

it('rejeita upload acima do tamanho máximo (422)', function () {
    Storage::fake(VoiceIntroStore::DISK);
    config(['voice.max_bytes' => 1024]); // 1 KB — o fixture de ~33 KB excede
    $performer = voicePerformer();

    $this->actingAs($performer->user)
        ->post(route('performer.voice-intro.store'), ['audio' => voiceUpload()])
        ->assertStatus(422);
});

// ─── ffmpeg REAL (pula sem o binário — ex.: CI) ──────────────────────────────

it('sanitiza um áudio REAL de ponta a ponta e STRIPA o metadado (ID3)', function () {
    try {
        app(VoiceProcessingService::class)->assertAvailable();
    } catch (VoiceProcessingException) {
        test()->markTestSkipped('ffmpeg indisponível neste ambiente.');
    }

    // Gera um MP3 de entrada COM uma tag de título "SEGREDO" (metadado a remover).
    $src = tempnam(sys_get_temp_dir(), 'vi_src_').'.mp3';
    $ffmpeg = config('voice.ffmpeg');
    exec(escapeshellarg($ffmpeg).' -y -f lavfi -i "sine=frequency=440:duration=2" -ac 1 -metadata title=SEGREDO -c:a libmp3lame '.escapeshellarg($src).' 2>/dev/null');

    $dest = tempnam(sys_get_temp_dir(), 'vi_out_').'.mp3';
    app(VoiceProcessingService::class)->sanitize($src, $dest);

    // O output É um MP3 e a duração é lida.
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer(file_get_contents($dest)))->toContain('audio')
        ->and(app(VoiceProcessingService::class)->probeDurationSeconds($dest))->toBeGreaterThanOrEqual(1.0);

    // O metadado sumiu: o ffprobe do output NÃO traz a tag title=SEGREDO.
    $ffprobe = config('voice.ffprobe');
    exec(escapeshellarg($ffprobe).' -v error -show_entries format_tags=title -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($dest).' 2>/dev/null', $out);
    expect(implode('', $out))->not->toContain('SEGREDO');

    @unlink($src);
    @unlink($dest);
});

it('probeDurationSeconds trata um arquivo NÃO-mídia como unreadable', function () {
    try {
        app(VoiceProcessingService::class)->assertAvailable();
    } catch (VoiceProcessingException) {
        test()->markTestSkipped('ffmpeg indisponível neste ambiente.');
    }

    $txt = tempnam(sys_get_temp_dir(), 'vi_txt_');
    file_put_contents($txt, 'isto nao e audio');

    try {
        app(VoiceProcessingService::class)->probeDurationSeconds($txt);
        $this->fail('deveria ter lançado unreadable');
    } catch (VoiceProcessingException $e) {
        expect($e->reason)->toBe(VoiceProcessingException::UNREADABLE);
    } finally {
        @unlink($txt);
    }
});
