<?php

use App\Events\GroupEnded;
use App\Events\GroupParticipantLeft;
use App\Events\GroupUpgradeRequested;
use App\Events\GroupUpgradeResolved;
use App\Jobs\RevokeGroupParticipants;
use App\Models\CallSession;
use App\Models\CallSessionParticipant;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * PR #141 (Sprint 15) — group show 1:X com cobrança por minuto INDEPENDENTE por
 * participante (spend_call/call_credit, split 70/30) e upgrade para 1:1. Reusa o
 * MinuteBiller do #140. Helpers com nome próprio (grp*) para não colidir com os
 * globais de PrivateCallTest/PublicLiveTest.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'livekit.max_participants_group' => 10,
        'features.call_enabled' => true,
    ]);
});

function fakeGroupKit(array $overrides = []): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('revokeParticipant')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();

    foreach ($overrides as $method => $return) {
        $lk->shouldReceive($method)->andReturn($return);
    }

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function grpPerformer(int $groupPrice = 10, ?int $privatePrice = 20): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    $profile->forceFill(['call_price_per_minute' => $privatePrice])->save();

    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function grpMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

/** Abre um group show ativo direto, com N participantes ativos (sem passar pela rota). */
function openGroup(User $performer, int $price = 10, int $maxParticipants = 5): CallSession
{
    $session = new CallSession([
        'performer_profile_id' => $performer->performerProfile->id,
        'room_name' => app(LiveKitService::class)->groupRoomName($performer->performerProfile->id),
        'price_per_minute' => $price,
        'max_duration_minutes' => null,
    ]);
    $session->forceFill([
        'type' => CallSession::TYPE_GROUP,
        'status' => 'active',
        'member_id' => null,
        'max_participants' => $maxParticipants,
        'upgrade_price_per_minute' => $performer->performerProfile->call_price_per_minute,
        'started_at' => now(),
        'minutes_billed' => 0,
    ])->save();

    return $session;
}

function joinGroup(CallSession $group, User $member, int $minutesBilled = 1): CallSessionParticipant
{
    $p = new CallSessionParticipant([
        'call_session_id' => $group->id,
        'member_id' => $member->id,
        'price_per_minute' => $group->price_per_minute,
    ]);
    $p->forceFill([
        'status' => 'active',
        'minutes_billed' => $minutesBilled,
        'joined_at' => now(),
    ])->save();

    return $p;
}

// ── Start / stop ─────────────────────────────────────────────────────────────

it('performer inicia group show com max 5: cria sala max 6 (5 + performer)', function () {
    $lk = fakeGroupKit();
    $lk->shouldReceive('createRoom')->once()->with(Mockery::type('string'), 6);
    $performer = grpPerformer(groupPrice: 10);

    $res = $this->actingAs($performer)
        ->postJson(route('group.start'), ['price_per_minute' => 10, 'max_participants' => 5])
        ->assertOk()
        ->assertJsonStructure(['token', 'wsUrl']);
    expect($res->json())->not->toHaveKey('roomName');

    $this->assertDatabaseHas('call_sessions', [
        'performer_profile_id' => $performer->performerProfile->id,
        'type' => 'group',
        'status' => 'active',
        'price_per_minute' => 10,
        'max_participants' => 5,
        'member_id' => null,
    ]);
});

it('start é idempotente sob double-submit — um group, uma sala', function () {
    $lk = fakeGroupKit();
    $lk->shouldReceive('createRoom')->once();
    $performer = grpPerformer();

    $this->actingAs($performer)->postJson(route('group.start'), ['price_per_minute' => 10, 'max_participants' => 5])->assertOk();
    $this->actingAs($performer)->postJson(route('group.start'), ['price_per_minute' => 10, 'max_participants' => 5])->assertOk();

    expect(CallSession::where('type', 'group')->count())->toBe(1);
});

it('performer encerra o group: todos os participantes desconectados', function () {
    $lk = fakeGroupKit();
    $lk->shouldReceive('deleteRoom')->once();
    Event::fake([GroupEnded::class]);
    $performer = grpPerformer();
    $group = openGroup($performer);
    $m1 = grpMember(100);
    $m2 = grpMember(100);
    joinGroup($group, $m1);
    joinGroup($group, $m2);

    $this->actingAs($performer)->postJson(route('group.stop'))->assertOk();

    expect($group->fresh()->status)->toBe('ended')
        ->and(CallSessionParticipant::where('call_session_id', $group->id)->active()->count())->toBe(0);
    Event::assertDispatched(GroupEnded::class, fn ($e) => $e->memberUserId === $m1->id && $e->reason === 'performer_stopped');
    Event::assertDispatched(GroupEnded::class, fn ($e) => $e->memberUserId === $m2->id);
});

// ── Join / billing ───────────────────────────────────────────────────────────

it('membro entra e paga o preço de grupo (primeiro minuto)', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $member = grpMember(100);

    $res = $this->actingAs($member)
        ->postJson(route('group.join', $group->id))
        ->assertOk()
        ->assertJsonStructure(['token', 'wsUrl', 'group_id']);

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(90) // 100 - 10
        ->and(CallSessionParticipant::where('call_session_id', $group->id)->where('member_id', $member->id)->value('minutes_billed'))->toBe(1);
});

it('3 membros na sala: performer ganha 3x o preço-grupo por minuto', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $members = collect(range(1, 3))->map(fn () => grpMember(100));

    foreach ($members as $m) {
        $this->actingAs($m)->postJson(route('group.join', $group->id))->assertOk();
    }

    // 3 débitos de 10 (spend_call) e 3 créditos de 7 (call_credit, 70% de 10).
    expect((int) TokenLedger::where('entry_type', 'spend_call')->sum('amount'))->toBe(-30)
        ->and((int) TokenLedger::where('entry_type', 'call_credit')->sum('amount'))->toBe(21); // 3 × 7
});

it('heartbeat por participante é independente: cobra só o próprio minuto', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $a = grpMember(100);
    $b = grpMember(100);
    joinGroup($group, $a, minutesBilled: 1);
    joinGroup($group, $b, minutesBilled: 1);
    app(TokenService::class)->debit($a, 10, 'spend_call'); // simula 1º minuto de A
    app(TokenService::class)->debit($b, 10, 'spend_call'); // e de B

    $this->travel(61)->seconds();

    // Só A dá heartbeat: só A é cobrado o minuto 2.
    $this->actingAs($a)->postJson(route('group.heartbeat', $group->id))->assertOk();

    expect(CallSessionParticipant::where('member_id', $a->id)->value('minutes_billed'))->toBe(2)
        ->and(CallSessionParticipant::where('member_id', $b->id)->value('minutes_billed'))->toBe(1); // B intacto
});

it('heartbeat idempotente por minuto por participante: dois no mesmo minuto → 2º no-op', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $a = grpMember(100);
    joinGroup($group, $a, minutesBilled: 1);

    $this->travel(61)->seconds();
    $this->actingAs($a)->postJson(route('group.heartbeat', $group->id))->assertOk();
    $this->actingAs($a)->postJson(route('group.heartbeat', $group->id))->assertOk();

    expect(CallSessionParticipant::where('member_id', $a->id)->value('minutes_billed'))->toBe(2)
        ->and(TokenLedger::where('entry_type', 'spend_call')->count())->toBe(1); // um débito de heartbeat
});

it('membro sem saldo: SÓ ele desconecta, os outros ficam', function () {
    fakeGroupKit();
    Event::fake([GroupParticipantLeft::class]);
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $poor = grpMember(10); // exatamente 1 minuto
    $rich = grpMember(100);
    joinGroup($group, $poor, minutesBilled: 1);
    joinGroup($group, $rich, minutesBilled: 1);
    app(TokenService::class)->debit($poor, 10, 'spend_call'); // saldo 0

    $this->travel(61)->seconds();
    $res = $this->actingAs($poor)->postJson(route('group.heartbeat', $group->id))->assertOk();

    expect($res->json('can_continue'))->toBeFalse()
        ->and(CallSessionParticipant::where('member_id', $poor->id)->value('status'))->toBe('ended')
        ->and(CallSessionParticipant::where('member_id', $rich->id)->value('status'))->toBe('active'); // o outro fica
    // A performer é avisada com o FanAlias LABEL (não o id cru) e SEM motivo — o
    // evento não carrega "sem saldo" nem nada financeiro (M.13.10).
    $expectedLabel = \App\Support\FanAlias::label($performer->performerProfile->id, $poor->id);
    Event::assertDispatched(GroupParticipantLeft::class, fn ($e) => $e->performerUserId === $performer->id
        && $e->memberLabel === $expectedLabel);
});

it('saldo negativo nunca acontece no group', function () {
    fakeGroupKit();
    Event::fake();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $m = grpMember(10);
    joinGroup($group, $m, minutesBilled: 1);
    app(TokenService::class)->debit($m, 10, 'spend_call'); // saldo 0

    $this->travel(61)->seconds();
    $this->actingAs($m)->postJson(route('group.heartbeat', $group->id));
    $this->travel(61)->seconds();
    $this->actingAs($m)->postJson(route('group.heartbeat', $group->id));

    expect(app(TokenService::class)->balance($m->fresh()))->toBe(0);
});

// ── Vagas / teto ─────────────────────────────────────────────────────────────

it('teto de participantes: quando o group está cheio, o próximo membro é rejeitado (409)', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10, maxParticipants: 3);
    // 3 vagas preenchidas.
    joinGroup($group, grpMember(100));
    joinGroup($group, grpMember(100));
    joinGroup($group, grpMember(100));
    $sixth = grpMember(100);

    $this->actingAs($sixth)
        ->postJson(route('group.join', $group->id))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'conflict');

    expect(CallSessionParticipant::where('call_session_id', $group->id)->active()->count())->toBe(3);
});

// ── Exclusividade / IDOR ─────────────────────────────────────────────────────

it('membro já em group ativo não é aceito numa 1:1 (exclusividade bidirecional)', function () {
    fakeGroupKit();
    $performerA = grpPerformer(groupPrice: 10);
    $group = openGroup($performerA, price: 10);
    $member = grpMember(100);
    joinGroup($group, $member); // membro ativo no group

    // Uma 1:1 pendente para outra performer + accept → deve recusar (409).
    $performerB = grpPerformer(groupPrice: 10, privatePrice: 20);
    $call = CallSession::create([
        'performer_profile_id' => $performerB->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => app(LiveKitService::class)->callRoomName(),
        'price_per_minute' => 20,
    ]);
    $call->forceFill(['type' => 'private'])->save();

    $this->actingAs($performerB)
        ->postJson(route('call.accept', $call->id))
        ->assertStatus(409);
});

it('IDOR: membro de fora não faz heartbeat na participação de terceiro (404)', function () {
    fakeGroupKit();
    $performer = grpPerformer();
    $group = openGroup($performer);
    $member = grpMember(100);
    joinGroup($group, $member);
    $stranger = grpMember(100);

    $this->actingAs($stranger)
        ->postJson(route('group.heartbeat', $group->id))
        ->assertNotFound();
});

it('IDOR: performer não aceita upgrade de group de outra performer (404)', function () {
    fakeGroupKit();
    $performerA = grpPerformer();
    $performerB = grpPerformer();
    $group = openGroup($performerA);
    $member = grpMember(100);
    $p = joinGroup($group, $member);
    $group->forceFill(['upgrade_requested_by' => $member->id, 'upgrade_requested_at' => now()])->save();

    $this->actingAs($performerB)
        ->postJson(route('group.upgrade.accept', $group->id))
        ->assertNotFound();
});

// ── Upgrade para 1:1 ─────────────────────────────────────────────────────────

it('upgrade: membro pede, performer aceita → outros desconectados (job de revoke agendado) e preço vira 1:1', function () {
    fakeGroupKit();
    Bus::fake([RevokeGroupParticipants::class]);
    Event::fake([GroupUpgradeResolved::class, GroupEnded::class]);
    $performer = grpPerformer(groupPrice: 10, privatePrice: 20);
    $group = openGroup($performer, price: 10);
    $requester = grpMember(100);
    $other = grpMember(100);
    joinGroup($group, $requester, minutesBilled: 1);
    joinGroup($group, $other, minutesBilled: 1);
    $group->forceFill(['upgrade_requested_by' => $requester->id, 'upgrade_requested_at' => now()])->save();

    $this->actingAs($performer)->postJson(route('group.upgrade.accept', $group->id))->assertOk();

    $group->refresh();
    expect($group->type)->toBe('private') // bloqueia novos joins
        ->and($group->upgrade_requested_by)->toBeNull()
        ->and(CallSessionParticipant::where('member_id', $requester->id)->value('price_per_minute'))->toBe(20) // preço 1:1
        ->and(CallSessionParticipant::where('member_id', $requester->id)->value('status'))->toBe('active')
        ->and(CallSessionParticipant::where('member_id', $other->id)->value('status'))->toBe('ended'); // outro sai

    Bus::assertDispatched(RevokeGroupParticipants::class);
    Event::assertDispatched(GroupUpgradeResolved::class, fn ($e) => $e->memberUserId === $requester->id && $e->accepted === true);
    Event::assertDispatched(GroupEnded::class, fn ($e) => $e->memberUserId === $other->id && $e->reason === 'upgraded');
});

it('preço muda de grupo para 1:1 após upgrade: o próximo heartbeat do sobrevivente cobra o preço 1:1', function () {
    fakeGroupKit();
    Bus::fake();
    Event::fake();
    $performer = grpPerformer(groupPrice: 10, privatePrice: 20);
    $group = openGroup($performer, price: 10);
    $requester = grpMember(100);
    joinGroup($group, $requester, minutesBilled: 1);
    app(TokenService::class)->debit($requester, 10, 'spend_call'); // 1º minuto grupo → saldo 90
    $group->forceFill(['upgrade_requested_by' => $requester->id, 'upgrade_requested_at' => now()])->save();

    $this->actingAs($performer)->postJson(route('group.upgrade.accept', $group->id))->assertOk();

    $this->travel(61)->seconds();
    $this->actingAs($requester)->postJson(route('group.heartbeat', $group->id))->assertOk();

    // Minuto 2 cobrado ao preço 1:1 (20), não ao de grupo (10): 90 - 20 = 70.
    expect(app(TokenService::class)->balance($requester->fresh()))->toBe(70);
});

it('upgrade: performer recusa → nada muda, grupo continua', function () {
    fakeGroupKit();
    Event::fake([GroupUpgradeResolved::class]);
    $performer = grpPerformer();
    $group = openGroup($performer);
    $requester = grpMember(100);
    $other = grpMember(100);
    joinGroup($group, $requester);
    joinGroup($group, $other);
    $group->forceFill(['upgrade_requested_by' => $requester->id, 'upgrade_requested_at' => now()])->save();

    $this->actingAs($performer)->postJson(route('group.upgrade.decline', $group->id))->assertOk();

    $group->refresh();
    expect($group->type)->toBe('group') // continua group
        ->and($group->upgrade_requested_by)->toBeNull()
        ->and(CallSessionParticipant::where('call_session_id', $group->id)->active()->count())->toBe(2); // todos ficam
    Event::assertDispatched(GroupUpgradeResolved::class, fn ($e) => $e->memberUserId === $requester->id && $e->accepted === false);
});

it('upgrade: dois pedem ao mesmo tempo → o primeiro tem prioridade, o segundo recebe 409', function () {
    fakeGroupKit();
    Event::fake([GroupUpgradeRequested::class]);
    $performer = grpPerformer();
    $group = openGroup($performer);
    $first = grpMember(100);
    $second = grpMember(100);
    joinGroup($group, $first);
    joinGroup($group, $second);

    $this->actingAs($first)->postJson(route('group.upgrade.request', $group->id))->assertOk();
    $this->actingAs($second)
        ->postJson(route('group.upgrade.request', $group->id))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Já existe uma solicitação pendente.');

    expect($group->fresh()->upgrade_requested_by)->toBe($first->id);
});

it('upgrade-request para performer sem preço 1:1 → 422 unavailable', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10, privatePrice: 20);
    $group = openGroup($performer, price: 10);
    $group->forceFill(['upgrade_price_per_minute' => null])->save(); // sem preço 1:1
    $member = grpMember(100);
    joinGroup($group, $member);

    $this->actingAs($member)
        ->postJson(route('group.upgrade.request', $group->id))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unavailable');
});

// ── Split / payout / ban ─────────────────────────────────────────────────────

it('split 70/30 com applied_rate=70 congelado por participante', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $member = grpMember(100);

    $this->actingAs($member)->postJson(route('group.join', $group->id))->assertOk();

    $credit = TokenLedger::where('entry_type', 'call_credit')->firstOrFail();
    expect($credit->amount)->toBe(7)          // round(10 × 0.70)
        ->and($credit->applied_rate)->toBe(70)
        ->and($credit->reference_type)->toBe(CallSessionParticipant::class);
});

it('performer banida durante o group: sala fechada, sessão e participantes encerrados', function () {
    $lk = fakeGroupKit();
    $lk->shouldReceive('deleteRoom')->once();
    $performer = grpPerformer();
    $group = openGroup($performer);
    $m1 = grpMember(100);
    joinGroup($group, $m1);
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->post(route('admin.users.ban', $performer), ['reason' => 'abuso']);

    expect($group->fresh()->status)->toBe('ended')
        ->and(CallSessionParticipant::where('call_session_id', $group->id)->active()->count())->toBe(0);
});

it('membro banido durante o group: SÓ ele é removido, os outros ficam', function () {
    $lk = fakeGroupKit();
    $lk->shouldReceive('revokeParticipant')->once();
    $performer = grpPerformer();
    $group = openGroup($performer);
    $banned = grpMember(100);
    $other = grpMember(100);
    joinGroup($group, $banned);
    joinGroup($group, $other);
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->post(route('admin.users.ban', $banned), ['reason' => 'abuso']);

    expect(CallSessionParticipant::where('member_id', $banned->id)->value('status'))->toBe('ended')
        ->and(CallSessionParticipant::where('member_id', $other->id)->value('status'))->toBe('active')
        ->and($group->fresh()->status)->toBe('active'); // o group segue
});

// ── Contaminação cross-type: rota 1:1 não toca sessão group ──────────────────

it('performer NÃO encerra a própria group pela porta 1:1 /call/{id}/end (404, group intacto)', function () {
    fakeGroupKit();
    $performer = grpPerformer();
    $group = openGroup($performer);
    $member = grpMember(100);
    joinGroup($group, $member);

    // A porta 1:1 recebe o id da sessão group — deve recusar (404), sem encerrar
    // nem deixar participantes órfãos (achado 🔴 da revisão de código).
    $this->actingAs($performer)
        ->postJson(route('call.end', $group->id))
        ->assertNotFound();

    expect($group->fresh()->status)->toBe('active')
        ->and(CallSessionParticipant::where('member_id', $member->id)->value('status'))->toBe('active');
});

it('performer NÃO renova a própria group pela porta 1:1 /call/{id}/token-refresh (404)', function () {
    fakeGroupKit();
    $performer = grpPerformer();
    $group = openGroup($performer);
    joinGroup($group, grpMember(100));

    $this->actingAs($performer)
        ->postJson(route('call.token-refresh', $group->id))
        ->assertNotFound();
});

// ── Reaper de participações abandonadas ──────────────────────────────────────

it('reaper encerra participação abandonada (sem heartbeat) e destrava o membro', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $ghost = grpMember(1000);
    joinGroup($group, $ghost, minutesBilled: 1); // pagou o minuto 1 (cobre 60s)

    // 5 min depois, sem heartbeat: tempo pago (60s) venceu há muito (> folga 120s).
    $this->travel(5)->minutes();
    $this->artisan('calls:reap-stale')->assertSuccessful();

    expect(CallSessionParticipant::where('member_id', $ghost->id)->value('status'))->toBe('ended')
        ->and(\App\Services\CallService::memberIsBusy($ghost->id))->toBeFalse(); // destravado
});

it('reaper NÃO encerra participante saudável (minutes_billed acompanha o tempo)', function () {
    fakeGroupKit();
    $performer = grpPerformer(groupPrice: 10);
    $group = openGroup($performer, price: 10);
    $healthy = grpMember(1000);
    // Pagou 6 minutos (cobre 360s); passados 5 min, ainda dentro do pago + folga.
    joinGroup($group, $healthy, minutesBilled: 6);

    $this->travel(5)->minutes();
    $this->artisan('calls:reap-stale')->assertSuccessful();

    expect(CallSessionParticipant::where('member_id', $healthy->id)->value('status'))->toBe('active');
});

// ── Feature flag ─────────────────────────────────────────────────────────────

it('feature flag desligada: start e join dão 403', function () {
    fakeGroupKit();
    config(['features.call_enabled' => false]);
    $performer = grpPerformer();
    $group = openGroup($performer);
    $member = grpMember(100);

    $this->actingAs($performer)
        ->postJson(route('group.start'), ['price_per_minute' => 10, 'max_participants' => 5])
        ->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('group.join', $group->id))
        ->assertForbidden();
});
