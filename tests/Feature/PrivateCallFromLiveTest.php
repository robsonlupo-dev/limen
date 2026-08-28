<?php

use App\Events\LiveStateChanged;
use App\Models\CallSession;
use App\Models\LiveSession;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\LiveSessionService;
use App\Services\TokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * feat/private-call-from-live — chamada privada pedida DURANTE a live pública. A
 * economia (débito/crédito por minuto, split 70/30, R1–R4, idempotência, saldo nunca
 * negativo) REUSA CallService/MinuteBiller: estes testes provam que o reuso está
 * correto pelo mesmo caminho de rota que o membro usa da live, MAIS a pausa/retoma da
 * live, o isolamento de mídia (privacidade) e o gate de exclusividade.
 *
 * Helpers próprios (pcfl*) para rodar isolado.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.call_enabled' => true,
        'features.live_enabled' => true,
    ]);
});

function pcflKit(): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom', 'deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();
    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function pcflPerformer(int $price = 10): User
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
    $user->performerProfile->forceFill(['call_price_per_minute' => $price])->save();
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function pcflMember(int $balance = 0): User
{
    $user = User::factory()->create([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ]);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

function pcflOpenLive(User $performer): LiveSession
{
    $profile = $performer->performerProfile;
    $session = LiveSession::create([
        'performer_profile_id' => $profile->id,
        'room_name' => app(LiveKitService::class)->liveRoomName($profile->id),
        'status' => 'live', 'viewer_count' => 0, 'started_at' => now(),
    ]);
    $profile->forceFill(['is_live' => true])->save();

    return $session;
}

/** request → accept via as rotas reais (o caminho que o membro usa da live). */
function pcflAcceptedCall(User $performer, User $member): CallSession
{
    $callId = test()->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated()->json('call_id');
    test()->actingAs($performer)->postJson(route('call.accept', $callId))->assertOk();

    return CallSession::find($callId);
}

function pcflSpend(): int
{
    return (int) TokenLedger::where('entry_type', 'spend_call')->sum('amount');
}

// ── Economia: espera, minutos, split, saldo ──────────────────────────────────

it('pedido RECUSADO não move token', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $member = pcflMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))->json('call_id');
    $this->actingAs($performer)->postJson(route('call.decline', $callId))->assertOk();

    expect(TokenLedger::whereIn('entry_type', ['spend_call', 'call_credit'])->count())->toBe(0)
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(100);
});

it('pedido EXPIRADO não move token', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $member = pcflMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))->json('call_id');

    // Passa o TTL do pending e expira em lote (command calls:expire-pending).
    $this->travel(CallSession::PENDING_TTL_SECONDS + 5)->seconds();
    app(\App\Services\CallService::class)->expireStalePending();

    expect(CallSession::find($callId)->status)->toBe('expired')
        ->and(TokenLedger::whereIn('entry_type', ['spend_call', 'call_credit'])->count())->toBe(0)
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(100);
});

it('a ESPERA entre pedido e aceite NÃO é cobrada', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $member = pcflMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))->json('call_id');
    // Nada cobrado ainda: o pedido não inicia o relógio.
    expect(TokenLedger::whereIn('entry_type', ['spend_call', 'call_credit'])->count())->toBe(0);

    // A performer demora 40s para aceitar — esse tempo não conta.
    $this->travel(40)->seconds();
    $this->actingAs($performer)->postJson(route('call.accept', $callId))->assertOk();

    // Só o 1º minuto (cobrado no aceite): a espera não virou minuto.
    expect(pcflSpend())->toBe(-10)
        ->and(CallSession::find($callId)->minutes_billed)->toBe(1);
});

it('chamada de 3 min a 10/min debita 30 e credita 21,0000 (70/30)', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $member = pcflMember(100);
    $call = pcflAcceptedCall($performer, $member); // minuto 1

    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk(); // minuto 2
    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk(); // minuto 3

    expect($call->fresh()->minutes_billed)->toBe(3)
        ->and(pcflSpend())->toBe(-30)
        ->and(TokenLedger::where('entry_type', 'call_credit')->get()->sum(fn ($e) => (float) $e->getRawOriginal('amount')))->toBe(21.0)
        ->and((string) TokenLedger::where('entry_type', 'call_credit')->latest('id')->first()->getRawOriginal('amount'))->toBe('7.0000');
});

it('saldo insuficiente encerra SEM negativar, e o aviso cabe no 1º minuto (exemplo 40/30)', function () {
    pcflKit();
    $performer = pcflPerformer(30);
    $member = pcflMember(40);
    $call = pcflAcceptedCall($performer, $member); // minuto 1: 40 → 10

    // Heartbeat IMEDIATO (o front roda um no início): já avisa que o saldo NÃO cobre o
    // próximo minuto (minutes_left=0), dentro do 1º minuto, com tempo de comprar.
    $hb = $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();
    expect($hb->json('minutes_left'))->toBe(0)
        ->and($hb->json('can_continue'))->toBeTrue()          // o minuto 1 ainda vale
        ->and($hb->json('balance_remaining'))->toBe(10);

    // Não comprou: no minuto 2, encerra sem cobrar (saldo 10 < 30), nunca negativo.
    $this->travel(61)->seconds();
    $end = $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();
    expect($end->json('can_continue'))->toBeFalse()
        ->and($call->fresh()->status)->toBe('ended')
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(10) // nunca < 0, minuto não prestado não cobra
        ->and(pcflSpend())->toBe(-30); // só os 30 do minuto 1
});

it('comprar tokens DURANTE a chamada: o saldo novo vale no minuto seguinte, sem reconectar', function () {
    pcflKit();
    $performer = pcflPerformer(30);
    $member = pcflMember(40);
    $call = pcflAcceptedCall($performer, $member); // minuto 1: 40 → 10

    // Simula o webhook do PIX creditando durante a chamada (a conexão não caiu).
    app(TokenService::class)->credit($member, 60, 'purchase'); // 10 → 70

    // Minuto 2 agora cabe: cobra e CONTINUA (o saldo novo valeu na hora).
    $this->travel(61)->seconds();
    $hb = $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();
    expect($hb->json('can_continue'))->toBeTrue()
        ->and($call->fresh()->minutes_billed)->toBe(2)
        ->and($call->fresh()->status)->toBe('active')
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(40); // 70 − 30
});

// ── Pausa / retoma da live ───────────────────────────────────────────────────

it('pausar a live NÃO desconecta quem assiste (segue ativa, só sinaliza)', function () {
    Event::fake([LiveStateChanged::class]);
    pcflKit();
    $performer = pcflPerformer(10);
    $live = pcflOpenLive($performer);
    $member = pcflMember(100);
    pcflAcceptedCall($performer, $member); // há chamada ativa → pode pausar

    app(LiveSessionService::class)->pause($performer);

    expect($live->fresh()->isPaused())->toBeTrue();
    Event::assertDispatched(LiveStateChanged::class, fn ($e) => $e->paused === true);

    // O viewer NÃO cai: show e viewer-count seguem 200 e trazem paused=true.
    $this->actingAs($member)->get(route('live.show', $performer->performerProfile->slug))
        ->assertOk()->assertInertia(fn ($p) => $p->where('paused', true));
    $this->actingAs($member)->getJson(route('live.viewer-count', $performer->performerProfile->slug))
        ->assertOk()->assertJson(['paused' => true]);

    // Retoma: paused_at limpo + broadcast.
    app(LiveSessionService::class)->resume($performer);
    expect($live->fresh()->isPaused())->toBeFalse();
    Event::assertDispatched(LiveStateChanged::class, fn ($e) => $e->paused === false);
});

it('a rede de segurança RETOMA a live na leitura se a chamada acabou (a performer caiu)', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $live = pcflOpenLive($performer);
    $member = pcflMember(100);
    $call = pcflAcceptedCall($performer, $member);
    app(LiveSessionService::class)->pause($performer);
    expect($live->fresh()->isPaused())->toBeTrue();

    // A chamada encerra mas a performer não chamou resume (aba fechada).
    $call->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

    // A próxima leitura de qualquer viewer retoma sozinha (não fica preso no "volta já").
    app(LiveSessionService::class)->activeFor($performer->performerProfile->fresh());
    expect($live->fresh()->isPaused())->toBeFalse();
});

it('pausar sem chamada ativa é NO-OP (não prende a live no volta já)', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $live = pcflOpenLive($performer);

    app(LiveSessionService::class)->pause($performer); // sem chamada ativa
    expect($live->fresh()->isPaused())->toBeFalse();
});

// ── Privacidade (B) e exclusividade (C) ──────────────────────────────────────

it('PRIVACIDADE: a sala da chamada é SEPARADA e o token do viewer da live não a alcança', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    $live = pcflOpenLive($performer);
    $member = pcflMember(100);
    $call = pcflAcceptedCall($performer, $member);

    // Salas LiveKit DISTINTAS — o A/V da chamada não roda na sala da live.
    expect($call->room_name)->not->toBe($live->room_name);

    // O JWT do viewer da live concede SÓ a sala da live (nunca a da chamada), e é
    // view-only (sem publish). Um espectador não consegue entrar/publicar na chamada.
    $viewer = pcflMember(0);
    $token = $this->actingAs($viewer)->get(route('live.show', $performer->performerProfile->slug))
        ->assertOk()->inertiaProps('token');
    $claims = JWT::decode($token, new Key(config('livekit.api_secret'), 'HS256'));
    expect($claims->video->room)->toBe($live->room_name)
        ->and($claims->video->room)->not->toBe($call->room_name)
        ->and($claims->video->canPublish ?? false)->toBeFalse();
});

it('EXCLUSIVIDADE: em chamada privada, a performer não aceita um SEGUNDO pedido', function () {
    pcflKit();
    $performer = pcflPerformer(10);
    pcflOpenLive($performer);
    $member1 = pcflMember(100);
    pcflAcceptedCall($performer, $member1); // ocupada

    // Outro membro tenta pedir → conflito (occupying()), nada criado.
    $member2 = pcflMember(100);
    $this->actingAs($member2)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertStatus(409);

    expect(CallSession::where('member_id', $member2->id)->count())->toBe(0);
});
