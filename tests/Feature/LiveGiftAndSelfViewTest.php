<?php

use App\Events\LiveReaction;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\LiveSessionService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Database\Seeders\GiftSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * fix/live-gift-and-selfview — dois bugs da sala ao vivo:
 *  1. Presente falhava mostrando só a mensagem GENÉRICA do front. Causa: um
 *     gift_slug que não resolve caía no `firstOrFail` do SendGiftRequest → 404 HTML,
 *     ilegível para o fetch (`response.json()` = null) → genérico. Agora é 422 JSON
 *     com motivo real; o front usa `errorMessage()` para nunca esconder o erro.
 *  2. A prévia do próprio vídeo da performer ficava PRETA: a faixa local era anexada
 *     ao <video> do estado ocioso, que o Vue desmontava ao virar 'live'. Corrigido
 *     no LiveRoom.vue (anexar após status='live' + nextTick). Testado por FONTE
 *     (sem Vitest no projeto), como MicroInteractions/VoiceIntroGuidance.
 *
 * Helpers próprios (lg*) para rodar isolado.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
});

function lgLiveKit(): void
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom', 'deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn(['pub'])->byDefault();
    app()->instance(LiveKitService::class, $lk);
}

function lgPerformer(): User
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

function lgMember(int $balance): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    app(TokenService::class)->credit($user, $balance, 'purchase');

    return $user;
}

function lgOpenLive(PerformerProfile $profile): LiveSession
{
    $s = LiveSession::create([
        'performer_profile_id' => $profile->id,
        'room_name' => app(LiveKitService::class)->liveRoomName($profile->id),
        'status' => 'live', 'viewer_count' => 0, 'started_at' => now(),
    ]);
    $profile->forceFill(['is_live' => true])->save();

    return $s;
}

// ── Bug 1: presente ──────────────────────────────────────────────────────────

it('presente inexistente devolve 422 JSON com motivo REAL, nao 404 HTML', function () {
    $this->seed(GiftSeeder::class);
    lgLiveKit();
    $performer = lgPerformer();
    lgOpenLive($performer->performerProfile);
    $member = lgMember(100);

    $res = $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->performerProfile->slug,
        'gift_slug' => 'naoexiste',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $res->assertStatus(422)
        ->assertHeader('content-type', 'application/json')
        ->assertJson(['reason' => 'gift_unavailable']);
    // A mensagem é real e legível (o fetch consegue lê-la), não um HTML genérico.
    expect($res->json('message'))->toContain('presente');
});

it('presente Rosa (4) debita 4 do membro, credita 3,2000 (80/20) e anima no feed com o alias', function () {
    Event::fake([LiveReaction::class]);
    $this->seed(GiftSeeder::class);
    lgLiveKit();
    $performer = lgPerformer();
    $session = lgOpenLive($performer->performerProfile);
    $member = lgMember(4);

    $this->actingAs($member)->postJson(route('gifts.send'), [
        'performer_slug' => $performer->performerProfile->slug,
        'gift_slug' => 'rosa',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus(201);

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(0);
    expect(app(LiveSessionService::class)->earnedThisLive($session->fresh()))->toBe('3.2000');

    Event::assertDispatched(LiveReaction::class, fn ($e) => $e->type === 'gift'
        && $e->giftSlug === 'rosa'
        && $e->amountTokens === 4
        && $e->fanAliasLabel === FanAlias::label($performer->performerProfile->id, $member->id));
});

// ── Bug 1: o front nunca esconde o erro real ─────────────────────────────────

it('o helper errorMessage existe e a sala o usa em vez do generico', function () {
    $http = file_get_contents(resource_path('js/lib/http.js'));
    expect($http)->toContain('export function errorMessage');

    $viewer = file_get_contents(resource_path('js/Components/LiveViewer.vue'));
    expect($viewer)
        ->toContain("errorMessage(e, 'Não foi possível enviar o presente.')")
        ->toContain("errorMessage(e, 'Não foi possível enviar a gorjeta.')");

    $chat = file_get_contents(resource_path('js/Components/LiveChat.vue'));
    expect($chat)->toContain('errorMessage(e,');
});

// ── Bug 2: prévia do próprio vídeo (por FONTE — sem Vitest) ───────────────────

it('a previa local anexa a faixa DEPOIS de status=live (fim do quadro preto)', function () {
    $src = file_get_contents(resource_path('js/Components/LiveRoom.vue'));

    // Anexa após montar o elemento do console: status='live' → nextTick → attach.
    expect($src)
        ->toContain('function attachLocalCamera')
        ->toContain('await nextTick()')
        ->toContain('attachLocalCamera()');

    // A ordem importa: o attach vem DEPOIS de status='live', não antes.
    $liveAt = strpos($src, "status.value = 'live'");
    $attachAt = strpos($src, 'attachLocalCamera()');
    expect($liveAt)->toBeLessThan($attachAt);
});

it('a previa e espelhada, muda (sem microfonia), com fallback de erro e ampliar', function () {
    $src = file_get_contents(resource_path('js/Components/LiveRoom.vue'));

    expect($src)
        ->toContain('-scale-x-100')          // espelhada (padrão de espelho)
        ->toContain('muted')                 // sem áudio de retorno
        ->toContain('cameraError')           // mostra o motivo, não quadro preto
        ->toContain('expanded = !expanded'); // pode ampliar e voltar
});
