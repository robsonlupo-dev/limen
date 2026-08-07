<?php

use App\Models\LiveSession;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\LivePreviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * PR #143 (Sprint 15) — preview animado da live no catálogo. O <LiveRoom> captura
 * um frame do vídeo local a cada 10s e envia; o backend grava UM JPEG por sessão
 * no disco privado `live_previews`, servido por rota autenticada (ServesPhotoBytes)
 * e apagado quando a live encerra. Validação leve: só tamanho (≤50KB) + JPEG.
 *
 * LiveKit é mockado (só a rede — roomExists) para `activeFor` enxergar a live viva.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
    Storage::fake('live_previews');
});

function fakePreviewKit(bool $roomExists = true): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn($roomExists)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();
    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function previewPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function previewMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function openPreviewLive(User $performer): LiveSession
{
    $session = LiveSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'room_name' => 'live-'.$performer->performerProfile->id.'-'.bin2hex(random_bytes(4)),
        'status' => 'live',
        'viewer_count' => 0,
        'started_at' => now(),
    ]);
    $performer->performerProfile->forceFill(['is_live' => true])->save();

    return $session;
}

/** Bytes de um JPEG real pequeno (via GD). */
function jpegBytes(int $w = 20, int $h = 20): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($img);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function jpegDataUrl(): string
{
    return 'data:image/jpeg;base64,'.base64_encode(jpegBytes());
}

// ── Upload ───────────────────────────────────────────────────────────────────

it('grava o frame quando a performer envia durante uma live ativa', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    $session = openPreviewLive($performer);

    $this->actingAs($performer)
        ->postJson(route('performer.live.preview'), ['frame' => jpegDataUrl()])
        ->assertOk();

    Storage::disk('live_previews')->assertExists($session->id.'.jpg');
});

it('rejeita o frame quando não há live ativa (422)', function () {
    fakePreviewKit();
    $performer = previewPerformer(); // sem live

    $this->actingAs($performer)
        ->postJson(route('performer.live.preview'), ['frame' => jpegDataUrl()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_live');

    expect(Storage::disk('live_previews')->allFiles())->toBeEmpty();
});

it('rejeita frame maior que 50KB', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    openPreviewLive($performer);
    // > 50KB de bytes → o teto de tamanho barra antes do sniff.
    $big = 'data:image/jpeg;base64,'.base64_encode(random_bytes(51300));

    $this->actingAs($performer)
        ->postJson(route('performer.live.preview'), ['frame' => $big])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_frame');

    expect(Storage::disk('live_previews')->allFiles())->toBeEmpty();
});

it('rejeita frame que não é JPEG (sniff sobre os bytes)', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    openPreviewLive($performer);
    // Bytes PNG, mas o prefixo mente "jpeg" — o finfo sobre os bytes reprova.
    $png = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($png);
    $pngBytes = ob_get_clean();
    imagedestroy($png);
    $fake = 'data:image/jpeg;base64,'.base64_encode($pngBytes);

    $this->actingAs($performer)
        ->postJson(route('performer.live.preview'), ['frame' => $fake])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_frame');
});

it('rejeita quem não é a performer (guest → redirect de login)', function () {
    fakePreviewKit();
    $this->postJson(route('performer.live.preview'), ['frame' => jpegDataUrl()])
        ->assertRedirect(route('login'));
});

// ── Serving ──────────────────────────────────────────────────────────────────

it('serve o frame para um membro autenticado', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    $session = openPreviewLive($performer);
    Storage::disk('live_previews')->put($session->id.'.jpg', jpegBytes());
    $member = previewMember();

    $res = $this->actingAs($member)
        ->get(route('live.preview', $performer->performerProfile->slug))
        ->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('image/jpeg');
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('o serving do frame exige membro autenticado (guest → redirect de login)', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    $session = openPreviewLive($performer);
    Storage::disk('live_previews')->put($session->id.'.jpg', jpegBytes());

    $this->get(route('live.preview', $performer->performerProfile->slug))
        ->assertRedirect(route('login'));
});

it('GET preview de performer SEM live → 404', function () {
    fakePreviewKit();
    $performer = previewPerformer(); // sem live ativa
    $member = previewMember();

    $this->actingAs($member)
        ->get(route('live.preview', $performer->performerProfile->slug))
        ->assertNotFound();
});

it('GET preview com live ativa mas SEM frame gravado → 404', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    openPreviewLive($performer); // live viva, mas nenhum frame no disco
    $member = previewMember();

    $this->actingAs($member)
        ->get(route('live.preview', $performer->performerProfile->slug))
        ->assertNotFound();
});

// ── Limpeza ──────────────────────────────────────────────────────────────────

it('apaga o frame quando a performer encerra a live (stop)', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    $session = openPreviewLive($performer);
    Storage::disk('live_previews')->put($session->id.'.jpg', jpegBytes());

    $this->actingAs($performer)->postJson(route('performer.live.stop'))->assertOk();

    Storage::disk('live_previews')->assertMissing($session->id.'.jpg');
});

it('apaga o frame quando a performer é banida', function () {
    fakePreviewKit();
    $performer = previewPerformer();
    $session = openPreviewLive($performer);
    Storage::disk('live_previews')->put($session->id.'.jpg', jpegBytes());
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->post(route('admin.users.ban', $performer), ['reason' => 'abuso']);

    Storage::disk('live_previews')->assertMissing($session->id.'.jpg');
});

it('o command live-previews:purge apaga frames órfãos (sem quadro há mais de 1h)', function () {
    fakePreviewKit();
    $disk = Storage::disk('live_previews');
    $disk->put('999.jpg', jpegBytes());   // órfão: sem live, mtime antigo
    $disk->put('1000.jpg', jpegBytes());  // recente: fica

    // Envelhece o mtime do órfão para >1h atrás (o lastModified lê o mtime real).
    touch($disk->path('999.jpg'), now()->getTimestamp() - 7200);

    $this->artisan('live-previews:purge')->assertSuccessful();

    $disk->assertMissing('999.jpg')
        ->assertExists('1000.jpg');
});

it('purgeOrphans retorna 0 sem estourar quando o diretório do disco não existe', function () {
    // O diretório do disco só passa a existir quando o PRIMEIRO frame é gravado.
    // Simula o live-previews:purge rodando antes de qualquer live ter transmitido:
    // remove o diretório do disco fakeado. purgeOrphans deve sair em 0, sem
    // estourar ao listar um path inexistente — e sem recriar o diretório (o guard
    // é leitura, faxina não materializa storage).
    $root = Storage::disk('live_previews')->path('');
    rmdir($root);
    expect(is_dir($root))->toBeFalse();

    expect(app(LivePreviewService::class)->purgeOrphans())->toBe(0);
    expect(is_dir($root))->toBeFalse();
});
