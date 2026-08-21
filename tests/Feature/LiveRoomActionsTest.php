<?php

use App\Events\LiveChatSent;
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
use Mockery\MockInterface;

/**
 * fix/live-room-actions — a sala ao vivo estava com chat/gorjeta/presente/contador
 * QUEBRADOS de ponta a ponta. Causa-raiz: o prop `performer` chegava ao Vue
 * embrulhado em `{ data: … }` (LiveViewController passava a resource crua), então
 * `props.performer.slug` era undefined e todo POST saía sem `performer_slug`. Além
 * disso o SendTipRequest não tinha FailsValidationAsJson, então a validação virava
 * redirect 302 → o fetch seguia até 200 → sucesso FALSO da gorjeta.
 *
 * Este teste prova o fluxo com dois participantes e trava as duas regressões.
 * Helpers próprios (lra*) para rodar isolado.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
});

function lraFakeLiveKit(array $overrides = []): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();
    $lk->shouldReceive('removeParticipant')->andReturnNull()->byDefault();

    foreach ($overrides as $method => $return) {
        $lk->shouldReceive($method)->andReturn($return);
    }

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function lraPerformer(): User
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

function lraMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

function lraOpenLive(PerformerProfile $profile): LiveSession
{
    $session = LiveSession::create([
        'performer_profile_id' => $profile->id,
        'room_name' => app(LiveKitService::class)->liveRoomName($profile->id),
        'status' => 'live',
        'viewer_count' => 0,
        'started_at' => now(),
    ]);
    $profile->forceFill(['is_live' => true])->save();

    return $session;
}

// ── Regressão 1: o prop performer NÃO chega embrulhado em `data` ──────────────

it('a pagina da live entrega performer.slug DESEMBRULHADO (nao {data})', function () {
    lraFakeLiveKit();
    $performer = lraPerformer();
    lraOpenLive($performer->performerProfile);
    $member = lraMember(50);
    $slug = $performer->performerProfile->slug;

    $res = $this->actingAs($member)->get(route('live.show', $slug))->assertOk();

    // O caminho pontilhado só resolve se o prop NÃO estiver em `{ data: … }`.
    $res->assertInertia(fn ($page) => $page
        ->where('performer.slug', $slug)
        ->where('performer.stage_name', $performer->performerProfile->stage_name)
    );
    expect($res->inertiaPage()['props']['performer'])->not->toHaveKey('data');
});

// ── Regressão 2: gorjeta sem performer_slug é 422 JSON, NUNCA redirect+sucesso ─

it('gorjeta sem performer_slug devolve 422 JSON (nao redirect) e NAO move token', function () {
    lraFakeLiveKit();
    $performer = lraPerformer();
    lraOpenLive($performer->performerProfile);
    $member = lraMember(50);

    // Sem performer_slug: a validação FALHA. Com o trait, é 422 JSON (o fetch lança);
    // sem ele, seria 302 → sucesso falso.
    $this->actingAs($member)
        ->postJson(route('tips.send'), ['amount' => 10, 'idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422);

    // Requisição que falha não move token nenhum.
    expect(app(TokenService::class)->balance($member->fresh()))->toBe(50);
});

// ── Fluxo de ponta a ponta: dois participantes ───────────────────────────────

it('gorjeta de 10 debita 10 do membro e credita 8,0000 a performer (80/20), no feed com alias', function () {
    Event::fake([LiveReaction::class]);
    lraFakeLiveKit();
    $performer = lraPerformer();
    $session = lraOpenLive($performer->performerProfile);
    $member = lraMember(10);
    $slug = $performer->performerProfile->slug;

    $this->actingAs($member)
        ->postJson(route('tips.send'), ['performer_slug' => $slug, 'amount' => 10, 'idempotency_key' => (string) Str::uuid()])
        ->assertStatus(201);

    // Membro debitado 10; performer creditada 8 (80/20, invariante M.13.6 — decisão do PO 2026-08-21).
    expect(app(TokenService::class)->balance($member->fresh()))->toBe(0);
    expect(app(LiveSessionService::class)->earnedThisLive($session))->toBe(8);

    // Anima no feed da sala (LiveReaction) com o FanAlias do membro, nunca o id/nome.
    Event::assertDispatched(LiveReaction::class, fn ($e) => $e->type === 'tip'
        && $e->amountTokens === 10
        && $e->fanAliasLabel === FanAlias::label($performer->performerProfile->id, $member->id));
});

it('presente Rosa (4) debita 4 e credita 3,2000 a performer (80/20)', function () {
    Event::fake([LiveReaction::class]);
    $this->seed(GiftSeeder::class);
    lraFakeLiveKit();
    $performer = lraPerformer();
    $session = lraOpenLive($performer->performerProfile);
    $member = lraMember(4);
    $slug = $performer->performerProfile->slug;

    $this->actingAs($member)
        ->postJson(route('gifts.send'), ['performer_slug' => $slug, 'gift_slug' => 'rosa', 'idempotency_key' => (string) Str::uuid()])
        ->assertStatus(201);

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(0);
    expect(app(LiveSessionService::class)->earnedThisLive($session->fresh()))->toBe('3.2000');
});

it('o ganho acumulado bate com a soma dos creditos (gorjeta + presente)', function () {
    $this->seed(GiftSeeder::class);
    lraFakeLiveKit();
    $performer = lraPerformer();
    $session = lraOpenLive($performer->performerProfile);
    $member = lraMember(14);
    $slug = $performer->performerProfile->slug;

    $this->actingAs($member)->postJson(route('tips.send'), ['performer_slug' => $slug, 'amount' => 10, 'idempotency_key' => (string) Str::uuid()])->assertStatus(201);
    $this->actingAs($member)->postJson(route('gifts.send'), ['performer_slug' => $slug, 'gift_slug' => 'rosa', 'idempotency_key' => (string) Str::uuid()])->assertStatus(201);

    // 8,0000 + 3,2000 = 11,2000.
    expect(app(LiveSessionService::class)->earnedThisLive($session->fresh()))->toBe('11.2000');
    expect(app(TokenService::class)->balance($member->fresh()))->toBe(0);
});

it('chat vai nos dois sentidos: membro -> performer e performer -> membro', function () {
    Event::fake([LiveChatSent::class]);
    lraFakeLiveKit();
    $performer = lraPerformer();
    lraOpenLive($performer->performerProfile);
    $member = lraMember(50);
    $slug = $performer->performerProfile->slug;

    $this->actingAs($member)->postJson(route('live.chat', $slug), ['body' => 'oi performer'])->assertOk();
    $this->actingAs($performer)->postJson(route('performer.live.chat'), ['body' => 'oi membro'])->assertOk();

    Event::assertDispatched(LiveChatSent::class, fn ($e) => $e->isPerformer === false && $e->body === 'oi performer');
    Event::assertDispatched(LiveChatSent::class, fn ($e) => $e->isPerformer === true && $e->body === 'oi membro');
});

it('o contador reflete a presenca real e e IGUAL nos dois lados', function () {
    // Mesma sala: 3 participantes (performer + 2 espectadores) → 2 dos dois lados.
    lraFakeLiveKit(['listParticipants' => ['pub', 'v1', 'v2']]);
    $performer = lraPerformer();
    lraOpenLive($performer->performerProfile);
    $member = lraMember();
    $slug = $performer->performerProfile->slug;

    $performerCount = $this->actingAs($performer)->getJson(route('performer.live.console'))->json('viewers');
    $memberCount = $this->actingAs($member)->getJson(route('live.viewer-count', $slug))->json('viewers');

    expect($performerCount)->toBe(2)->and($memberCount)->toBe(2);
});
