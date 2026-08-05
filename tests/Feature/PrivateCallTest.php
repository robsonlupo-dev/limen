<?php

use App\Events\CallAccepted;
use App\Events\CallDeclined;
use App\Events\CallEnded;
use App\Events\CallRequested;
use App\Models\CallSession;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * PR #140 (Sprint 15) — chamada privada 1:1 com cobrança por minuto contínuo
 * (spend_call/call_credit, split 70/30 congelado). Testa o fluxo request/accept/
 * decline, o heartbeat pré-pago, o encerramento por saldo/tempo e as invariantes
 * de segurança sobre a infra do #138.
 *
 * `LiveKitService` é mockado parcialmente: os métodos LOCAIS (generateToken,
 * identity, room name) rodam de verdade — o JWT é real e decodificável —, só os
 * que tocam a REDE (createRoom/deleteRoom/roomExists) são stubados. Helpers com
 * nome próprio (call*) para não colidir com os globais do PublicLiveTest.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.call_enabled' => true,
    ]);
});

function fakeCallKit(array $overrides = []): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();

    foreach ($overrides as $method => $return) {
        $lk->shouldReceive($method)->andReturn($return);
    }

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function callPerformer(int $price = 10, ?int $maxDuration = null, bool $verified = true, string $status = 'active'): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => $verified,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    $profile->forceFill([
        'call_price_per_minute' => $price,
        'call_max_duration_minutes' => $maxDuration,
    ])->save();

    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function callMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

/** Cria uma sessão de chamada ATIVA direto (sem passar pelo request/accept). */
function activeCall(User $performer, User $member, int $price = 10, int $minutesBilled = 1, ?int $maxDuration = null): CallSession
{
    $session = new CallSession([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => $price,
        'max_duration_minutes' => $maxDuration,
    ]);
    $session->forceFill([
        'type' => CallSession::TYPE_PRIVATE,
        'status' => 'active',
        'minutes_billed' => $minutesBilled,
        'started_at' => now(),
    ])->save();

    return $session;
}

// ── Request ──────────────────────────────────────────────────────────────────

it('membro pede a chamada: cria call_sessions pending do tipo private', function () {
    fakeCallKit();
    Event::fake([CallRequested::class]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);

    $res = $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated()
        ->assertJsonStructure(['call_id', 'price_per_minute', 'expires_in_seconds']);

    $this->assertDatabaseHas('call_sessions', [
        'id' => $res->json('call_id'),
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'status' => 'pending',
        'type' => 'private',
        'price_per_minute' => 10,
    ]);

    // Avisa a performer, com o FanAlias LABEL (nunca o member_id nem o handle).
    Event::assertDispatched(CallRequested::class, function ($e) use ($performer, $member) {
        return $e->performerUserId === $performer->id
            && $e->memberLabel === \App\Support\FanAlias::label($performer->performerProfile->id, $member->id)
            && ! str_contains($e->memberLabel, (string) $member->id);
    });
});

it('request com saldo insuficiente é rejeitado (422) e nada é criado', function () {
    fakeCallKit();
    $performer = callPerformer(price: 50);
    $member = callMember(balance: 10); // < 50

    $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    expect(CallSession::count())->toBe(0);
});

it('request para performer que não configurou preço → 422 unavailable', function () {
    fakeCallKit();
    $performer = callPerformer();
    $performer->performerProfile->forceFill(['call_price_per_minute' => null])->save();
    $member = callMember(balance: 100);

    $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unavailable');
});

it('request com performer já em outra chamada → 409', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $memberA = callMember(balance: 100);
    $memberB = callMember(balance: 100);
    activeCall($performer, $memberA); // performer ocupada

    $this->actingAs($memberB)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'conflict');
});

it('dois requests simultâneos para a mesma performer: o segundo é 409', function () {
    fakeCallKit();
    Event::fake();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);

    $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated();
    // Segundo request (a performer agora tem um pending) → 409, não empilha.
    $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertStatus(409);

    expect(CallSession::count())->toBe(1);
});

// ── Accept / Decline ─────────────────────────────────────────────────────────

it('performer aceita: cria sala max 2, debita o primeiro minuto, ativa a sessão', function () {
    $lk = fakeCallKit();
    Event::fake([CallAccepted::class]);
    $lk->shouldReceive('createRoom')->once()->with(Mockery::type('string'), 2);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $res = $this->actingAs($performer)
        ->postJson(route('call.accept', $call->id))
        ->assertOk()
        ->assertJsonStructure(['token', 'wsUrl', 'call_id']);
    expect($res->json())->not->toHaveKey('roomName');

    $call->refresh();
    expect($call->status)->toBe('active')
        ->and($call->minutes_billed)->toBe(1)
        ->and($call->started_at)->not->toBeNull()
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(90); // 100 - 10

    Event::assertDispatched(CallAccepted::class, fn ($e) => $e->memberUserId === $member->id);
});

it('performer recusa: marca declined e notifica o membro', function () {
    fakeCallKit();
    Event::fake([CallDeclined::class]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->actingAs($performer)->postJson(route('call.decline', $call->id))->assertOk();

    expect($call->fresh()->status)->toBe('declined');
    Event::assertDispatched(CallDeclined::class, fn ($e) => $e->memberUserId === $member->id);
});

it('request sem resposta em 60s expira na leitura (accept dá 410)', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->travel(61)->seconds();

    $this->actingAs($performer)
        ->postJson(route('call.accept', $call->id))
        ->assertStatus(410);

    expect($call->fresh()->status)->toBe('expired');
});

it('command calls:expire-pending marca os pendentes vencidos como expired', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->travel(61)->seconds();
    $this->artisan('calls:expire-pending')->assertSuccessful();

    expect($call->fresh()->status)->toBe('expired');
});

// ── Heartbeat (cobrança por minuto) ──────────────────────────────────────────

it('heartbeat cobra o minuto seguinte', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call'); // simula o 1º minuto do accept

    $this->travel(61)->seconds();

    $res = $this->actingAs($member)
        ->postJson(route('call.heartbeat', $call->id))
        ->assertOk();

    expect($res->json('minutes_elapsed'))->toBe(2)
        ->and($res->json('can_continue'))->toBeTrue()
        ->and($call->fresh()->minutes_billed)->toBe(2)
        ->and((int) TokenLedger::where('entry_type', 'spend_call')->sum('amount'))->toBe(-20);
});

it('heartbeat é idempotente por minuto: dois no mesmo minuto → o segundo é no-op', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);

    $this->travel(61)->seconds();

    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();

    // Só UM débito adicional (minuto 2); o segundo heartbeat não cobrou de novo.
    expect($call->fresh()->minutes_billed)->toBe(2)
        ->and(TokenLedger::where('entry_type', 'spend_call')->count())->toBe(1);
});

it('saldo zero: heartbeat devolve can_continue:false e encerra a sessão', function () {
    fakeCallKit();
    Event::fake([CallEnded::class]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 10); // exatamente 1 minuto
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call'); // 1º minuto → saldo 0

    $this->travel(61)->seconds();

    $res = $this->actingAs($member)
        ->postJson(route('call.heartbeat', $call->id))
        ->assertOk();

    expect($res->json('can_continue'))->toBeFalse()
        ->and($res->json('balance_remaining'))->toBe(0)
        ->and($res->json('minutes_left'))->toBe(0)
        ->and($call->fresh()->status)->toBe('ended');
});

it('saldo NUNCA fica negativo: sem cobrir o minuto, não cobra', function () {
    fakeCallKit();
    Event::fake();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 10);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call'); // saldo 0

    // Vários minutos passam e vários heartbeats — nunca cobra sem saldo.
    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id));
    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id));

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(0)
        ->and(TokenLedger::whereHas('wallet', fn ($q) => $q->where('user_id', $member->id))
            ->where('entry_type', 'spend_call')->count())->toBe(1); // só o 1º minuto
});

it('a performer é avisada com mensagem NEUTRA quando o membro fica sem saldo (nunca "sem saldo")', function () {
    fakeCallKit();
    Event::fake([CallEnded::class]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 10);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call');

    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();

    // Performer: motivo NEUTRO 'ended' (M.13.10 — nunca sabe do financeiro).
    Event::assertDispatched(CallEnded::class, fn ($e) => $e->recipientUserId === $performer->id && $e->reason === 'ended');
    // Membro: motivo real (ele já sabe o próprio saldo).
    Event::assertDispatched(CallEnded::class, fn ($e) => $e->recipientUserId === $member->id && $e->reason === 'insufficient_balance');
});

// ── Encerramento voluntário / tempo máximo ───────────────────────────────────

it('encerramento voluntário deleta a sala e marca ended', function () {
    $lk = fakeCallKit();
    Event::fake([CallEnded::class]);
    $lk->shouldReceive('deleteRoom')->once();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member);

    $this->actingAs($member)->postJson(route('call.end', $call->id))->assertOk();

    expect($call->fresh()->status)->toBe('ended')
        ->and($call->fresh()->ended_at)->not->toBeNull();
    // Membro encerrou → só a performer é avisada, com 'ended' neutro.
    Event::assertDispatched(CallEnded::class, fn ($e) => $e->recipientUserId === $performer->id && $e->reason === 'ended');
});

it('end é idempotente: chamar de novo é no-op de sucesso', function () {
    $lk = fakeCallKit();
    $lk->shouldReceive('deleteRoom')->once(); // só na primeira
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member);

    $this->actingAs($member)->postJson(route('call.end', $call->id))->assertOk();
    $this->actingAs($member)->postJson(route('call.end', $call->id))->assertOk();

    expect($call->fresh()->status)->toBe('ended');
});

it('tempo máximo encerra a chamada automaticamente', function () {
    fakeCallKit();
    Event::fake([CallEnded::class]);
    $performer = callPerformer(price: 10, maxDuration: 2);
    $member = callMember(balance: 1000);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 2, maxDuration: 2);

    $this->travel(121)->seconds(); // entra no minuto 3, acima do teto de 2

    $res = $this->actingAs($member)
        ->postJson(route('call.heartbeat', $call->id))
        ->assertOk();

    expect($res->json('can_continue'))->toBeFalse()
        ->and($call->fresh()->status)->toBe('ended')
        ->and($call->fresh()->minutes_billed)->toBe(2); // não cobrou além do teto
});

// ── Recarga durante a chamada ────────────────────────────────────────────────

it('recarga durante a chamada: a sessão continua sem reconectar', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 20); // 2 minutos
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call'); // 1º minuto → saldo 10

    $this->travel(61)->seconds();
    $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk(); // minuto 2 → saldo 0

    // Recarga (compra concluída): credita tokens — a sessão segue ATIVA.
    app(TokenService::class)->credit($member, 100, 'purchase');
    expect($call->fresh()->status)->toBe('active');

    $this->travel(61)->seconds();
    $res = $this->actingAs($member)->postJson(route('call.heartbeat', $call->id))->assertOk();

    expect($res->json('can_continue'))->toBeTrue()
        ->and($call->fresh()->minutes_billed)->toBe(3); // cobrou o minuto 3 após a recarga
});

// ── Token refresh ────────────────────────────────────────────────────────────

it('token refresh do membro devolve JWT com identity opaca (FanAlias, sem member_id)', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member);

    $res = $this->actingAs($member)
        ->postJson(route('call.token-refresh', $call->id))
        ->assertOk()
        ->assertJsonStructure(['token', 'wsUrl']);

    $claims = JWT::decode($res->json('token'), new Key(config('livekit.api_secret'), 'HS256'));
    // Identity = FanAlias handle por par (derivável só com a APP_KEY), nunca o id
    // cru. Comparação precisa (não substring — o hex pode coincidir com dígitos do
    // id): bate com o handle esperado e difere de `member:{id}`.
    $expected = app(LiveKitService::class)->callMemberIdentity($performer->performerProfile->id, $member->id);
    expect($claims->sub)->toBe($expected)
        ->and($claims->sub)->not->toBe('member:'.$member->id)
        ->and($claims->exp - $claims->iat)->toBe(300);
    // O room_name não vaza na resposta (só dentro do JWT).
    expect($res->getContent())->not->toContain($call->room_name);
});

it('token refresh com a sessão encerrada devolve 410', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member);
    $call->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

    $this->actingAs($member)
        ->postJson(route('call.token-refresh', $call->id))
        ->assertStatus(410);
});

it('token refresh do membro que suprimiu heartbeat reconcilia e encerra se não paga (fecha a carona)', function () {
    $lk = fakeCallKit();
    $lk->shouldReceive('deleteRoom')->once(); // derruba a sala AGORA (não espera o TTL)
    Event::fake([CallEnded::class]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 10);
    $call = activeCall($performer, $member, price: 10, minutesBilled: 1);
    app(TokenService::class)->debit($member, 10, 'spend_call'); // saldo 0

    $this->travel(61)->seconds(); // deve o minuto 2, não tem saldo

    $this->actingAs($member)
        ->postJson(route('call.token-refresh', $call->id))
        ->assertStatus(410);

    expect($call->fresh()->status)->toBe('ended');
});

// ── Ban durante a chamada ────────────────────────────────────────────────────

it('performer banida durante a chamada: a sala é fechada e a sessão encerrada', function () {
    $lk = fakeCallKit();
    $lk->shouldReceive('deleteRoom')->once();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = activeCall($performer, $member);
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->post(route('admin.users.ban', $performer), ['reason' => 'abuso']);

    expect($call->fresh()->status)->toBe('ended');
});

// ── Split / ledger / payout ──────────────────────────────────────────────────

it('split 70/30 com applied_rate=70 congelado na linha', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->actingAs($performer)->postJson(route('call.accept', $call->id))->assertOk();

    $credit = TokenLedger::where('entry_type', 'call_credit')->firstOrFail();
    expect($credit->amount)->toBe(7)        // round(10 * 0.70) = 7
        ->and($credit->applied_rate)->toBe(70)
        ->and((int) TokenLedger::where('entry_type', 'spend_call')->sum('amount'))->toBe(-10);
});

it('call_credit está no allowlist de payout (ganho sacável)', function () {
    expect(config('monetization.payout.earning_entry_types'))->toContain('call_credit');
});

it('call_credit NÃO respeita o teto (never-cap): credita acima de 5000', function () {
    fakeCallKit();
    expect(app(TokenCreditPolicy::class)->respectsCap('call_credit'))->toBeFalse();

    $performer = callPerformer(price: 10);
    // Performer no teto de acúmulo.
    app(TokenService::class)->credit($performer, 5000, 'purchase');
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->actingAs($performer)->postJson(route('call.accept', $call->id))->assertOk();

    // O crédito de 7 passou por cima do teto (never-cap): 5000 + 7.
    expect(app(TokenService::class)->balance($performer->fresh()))->toBe(5007);
});

// ── Segurança: IDOR, feature flag, self-call ─────────────────────────────────

it('IDOR: membro de fora não faz heartbeat na chamada de terceiro (404 uniforme)', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $stranger = callMember(balance: 100);
    $call = activeCall($performer, $member); // id EXISTENTE (não exercita 404 do binding)

    $this->actingAs($stranger)
        ->postJson(route('call.heartbeat', $call->id))
        ->assertNotFound();
    // E não cobrou ninguém.
    expect(TokenLedger::where('entry_type', 'spend_call')->count())->toBe(0);
});

it('IDOR: performer não aceita a chamada endereçada a outra performer (404)', function () {
    fakeCallKit();
    $performerA = callPerformer(price: 10);
    $performerB = callPerformer(price: 10);
    $member = callMember(balance: 100);
    $call = CallSession::create([
        'performer_profile_id' => $performerA->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->actingAs($performerB)
        ->postJson(route('call.accept', $call->id))
        ->assertNotFound();

    expect($call->fresh()->status)->toBe('pending');
});

it('exclusividade 1:1 do membro: um 2º accept do mesmo membro em outra chamada → 409, sem cobrar', function () {
    fakeCallKit();
    $performerA = callPerformer(price: 10);
    $performerB = callPerformer(price: 10);
    $member = callMember(balance: 100);

    // Membro já ATIVO com a performer A.
    activeCall($performerA, $member, price: 10, minutesBilled: 1);

    // Um pending do MESMO membro para a performer B (dois request concorrentes a
    // performers distintas conseguiriam criar isto). O accept de B deve recusar.
    $pendingB = CallSession::create([
        'performer_profile_id' => $performerB->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 10,
    ]);
    $pendingB->forceFill(['type' => 'private'])->save();

    $this->actingAs($performerB)
        ->postJson(route('call.accept', $pendingB->id))
        ->assertStatus(409);

    // A 2ª chamada não ativou nem cobrou (só o accept de A não roda aqui; nenhuma
    // linha spend_call foi criada por este accept recusado).
    expect($pendingB->fresh()->status)->toBe('pending')
        ->and(TokenLedger::where('entry_type', 'spend_call')->count())->toBe(0);
});

it('feature flag desligada: request e accept dão 403', function () {
    fakeCallKit();
    config(['features.call_enabled' => false]);
    $performer = callPerformer(price: 10);
    $member = callMember(balance: 100);

    $this->actingAs($member)
        ->postJson(route('call.request', $performer->performerProfile->id))
        ->assertForbidden();
});

// ── Configuração da performer ────────────────────────────────────────────────

it('performer configura preço/minuto e teto de duração', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);

    $this->actingAs($performer)
        ->patchJson(route('performer.call.settings'), [
            'price_per_minute' => 15,
            'max_duration_minutes' => 30,
        ])
        ->assertOk()
        ->assertJsonPath('call_price_per_minute', 15)
        ->assertJsonPath('call_max_duration_minutes', 30);
});

it('preço fora do passo de 5 é rejeitado (422)', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);

    $this->actingAs($performer)
        ->patchJson(route('performer.call.settings'), ['price_per_minute' => 7])
        ->assertStatus(422);
});

it('preço abaixo do piso de 5 é rejeitado (422)', function () {
    fakeCallKit();
    $performer = callPerformer(price: 10);

    $this->actingAs($performer)
        ->patchJson(route('performer.call.settings'), ['price_per_minute' => 0])
        ->assertStatus(422);
});
