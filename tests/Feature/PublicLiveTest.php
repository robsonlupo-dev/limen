<?php

use App\Models\Gift;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\GiftService;
use App\Services\LiveKitService;
use App\Services\PerformerCatalogService;
use App\Services\TokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * PR #139 (Sprint 15) — live pública GRÁTIS com gorjeta/presente. A live não
 * cobra entrada; a receita é gorjeta (TipService 80/20) e presente (GiftService
 * 75/25), já implementados. Testa o serving sobre a infra do #138.
 *
 * `LiveKitService` é mockado parcialmente: os métodos LOCAIS (generateToken,
 * identity, room name) rodam de verdade — o JWT emitido é real e decodificável —,
 * só os que tocam a REDE (createRoom/deleteRoom/roomExists/listParticipants) são
 * stubados. `acceptAllDocuments` é helper global do PerformerTwoFactorTest.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
});

function fakeLiveKit(array $overrides = []): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();

    foreach ($overrides as $method => $return) {
        $lk->shouldReceive($method)->andReturn($return);
    }

    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function livePerformer(bool $verified = true, string $status = 'active', int $followers = 0): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => $verified,
        'level' => 'iniciante',
        'split_pct' => 65,
    ])->forceFill(['followers_count' => $followers])->save();

    // Nome próprio (não o acceptAllDocuments do PerformerTwoFactorTest) para o
    // arquivo ser autossuficiente ao rodar isolado, sem colidir no suite cheio.
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function liveMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

/** Abre uma live_session ativa direto (sem passar pela rota performer). */
function openLive(PerformerProfile $profile): LiveSession
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

// ── Performer: start / stop ──────────────────────────────────────────────────

it('performer inicia a live: cria a sala, grava status=live e liga is_live', function () {
    $lk = fakeLiveKit();
    $lk->shouldReceive('createRoom')->once();
    $performer = livePerformer();

    $res = $this->actingAs($performer)->postJson(route('performer.live.start'));

    $res->assertOk()->assertJsonStructure(['token', 'wsUrl']);
    // O room_name nunca sai na resposta (invariante do #138).
    expect($res->json())->not->toHaveKey('roomName');
    $this->assertDatabaseHas('live_sessions', [
        'performer_profile_id' => $performer->performerProfile->id,
        'status' => 'live',
    ]);
    expect($performer->performerProfile->fresh()->is_live)->toBeTrue();
});

it('performer start is idempotent under double-submit — one room, one session', function () {
    $lk = fakeLiveKit();
    $lk->shouldReceive('createRoom')->once(); // NÃO duas salas
    $performer = livePerformer();

    $this->actingAs($performer)->postJson(route('performer.live.start'))->assertOk();
    $this->actingAs($performer)->postJson(route('performer.live.start'))->assertOk();

    expect(LiveSession::where('performer_profile_id', $performer->performerProfile->id)->count())->toBe(1);
});

it('performer encerra a live: deleta a sala, grava ended_at e desliga is_live', function () {
    $lk = fakeLiveKit();
    $lk->shouldReceive('deleteRoom')->once();
    $performer = livePerformer();
    $session = openLive($performer->performerProfile);

    $this->actingAs($performer)->postJson(route('performer.live.stop'))->assertOk();

    $session->refresh();
    expect($session->status)->toBe('ended')
        ->and($session->ended_at)->not->toBeNull()
        ->and($performer->performerProfile->fresh()->is_live)->toBeFalse();
});

it('renderiza o estúdio de transmissão para a performer ativa', function () {
    fakeLiveKit();
    $performer = livePerformer();

    $this->actingAs($performer)
        ->get(route('performer.live'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Performer/Live'));
});

// ── Membro: assistir grátis, gorjeta, presente ───────────────────────────────

it('membro entra na live sem gastar tokens', function () {
    fakeLiveKit();
    $performer = livePerformer();
    openLive($performer->performerProfile);
    $member = liveMember(balance: 100);

    $this->actingAs($member)
        ->get(route('live.show', $performer->performerProfile->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Live/Viewer')->has('token'));

    // Nenhum débito: saldo intacto, nenhuma linha de gasto no ledger.
    expect(app(TokenService::class)->balance($member->fresh()))->toBe(100)
        ->and(TokenLedger::whereIn('entry_type', ['spend_live', 'spend_tip', 'spend_gift'])->count())->toBe(0);
});

it('membro gorjeia durante a live: débito do membro + crédito da performer', function () {
    fakeLiveKit();
    $performer = livePerformer();
    openLive($performer->performerProfile);
    $member = liveMember(balance: 100);

    $this->actingAs($member)->postJson(route('tips.send'), [
        'performer_slug' => $performer->performerProfile->slug,
        'amount' => 50,
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSuccessful();

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(50) // 100 - 50
        ->and((int) TokenLedger::where('entry_type', 'tip_credit')->sum('amount'))->toBe(40); // 80% de 50
});

it('membro envia presente durante a live: GiftService debita e credita 75/25', function () {
    fakeLiveKit();
    $performer = livePerformer();
    openLive($performer->performerProfile);
    $member = liveMember(balance: 100);
    $gift = Gift::create(['name' => 'Rosa', 'slug' => 'rosa', 'price_tokens' => 4, 'active' => true]);

    app(GiftService::class)->send($member, $performer->performerProfile, $gift, (string) Str::uuid());

    expect(app(TokenService::class)->balance($member->fresh()))->toBe(96) // 100 - 4
        ->and((int) TokenLedger::where('entry_type', 'gift_credit')->sum('amount'))->toBe(3); // round(4*0.75)
});

// ── Token refresh ────────────────────────────────────────────────────────────

it('token refresh devolve um novo JWT válido', function () {
    fakeLiveKit();
    $performer = livePerformer();
    openLive($performer->performerProfile);
    $member = liveMember();

    $res = $this->actingAs($member)
        ->postJson(route('live.refresh', $performer->performerProfile->slug))
        ->assertOk()
        ->assertJsonStructure(['token', 'wsUrl']);

    $claims = JWT::decode($res->json('token'), new Key(config('livekit.api_secret'), 'HS256'));
    expect($claims->exp - $claims->iat)->toBe(300);
});

it('token refresh com a live encerrada devolve 410 e reconcilia a sessão', function () {
    // Sala morta no LiveKit → activeFor reconcilia para ended e o refresh dá 410.
    fakeLiveKit(['roomExists' => false]);
    $performer = livePerformer();
    $session = openLive($performer->performerProfile);
    $member = liveMember();

    $this->actingAs($member)
        ->postJson(route('live.refresh', $performer->performerProfile->slug))
        ->assertStatus(410);

    expect($session->fresh()->status)->toBe('ended')
        ->and($performer->performerProfile->fresh()->is_live)->toBeFalse();
});

// ── Ban da performer fecha a live ────────────────────────────────────────────

it('performer banida: a live é fechada (deleteRoom) e o membro perde o acesso', function () {
    $lk = fakeLiveKit();
    $lk->shouldReceive('deleteRoom')->once();
    $performer = livePerformer();
    $session = openLive($performer->performerProfile);
    $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->post(route('admin.users.ban', $performer), ['reason' => 'abuso']);

    expect($session->fresh()->status)->toBe('ended')
        ->and($performer->performerProfile->fresh()->is_live)->toBeFalse();
});

// ── Catálogo: badge + ordenação ──────────────────────────────────────────────

it('marca is_live no catálogo e coloca quem está ao vivo primeiro', function () {
    fakeLiveKit();
    $a = livePerformer(followers: 30)->performerProfile;       // mais seguidores
    $b = livePerformer(followers: 5)->performerProfile;        // menos, mas ao vivo
    $b->forceFill(['is_live' => true])->save();

    $items = app(PerformerCatalogService::class)->publicSearch()->items();
    $ids = collect($items)->pluck('id')->all();

    // B (ao vivo) antes de A (mais seguidores, offline).
    expect(array_search($b->id, $ids))->toBeLessThan(array_search($a->id, $ids));
    expect(collect($items)->firstWhere('id', $b->id)->is_live)->toBeTrue();
});

// ── Segurança: identity opaca, roomName não vaza, gates ───────────────────────

it('o JWT do membro leva identity opaca (sem member_id) e a sala não vaza nas props', function () {
    fakeLiveKit();
    $performer = livePerformer();
    $session = openLive($performer->performerProfile);
    $member = liveMember();

    $res = $this->actingAs($member)->get(route('live.show', $performer->performerProfile->slug))->assertOk();

    // Decodifica o token embutido nas props e confere a identity opaca.
    $claims = JWT::decode($res->inertiaProps('token'), new Key(config('livekit.api_secret'), 'HS256'));
    expect($claims->sub)->toMatch('/^lv_[0-9a-f]{16}$/')
        ->and($claims->sub)->not->toContain((string) $member->id);

    // O room_name NÃO aparece nas props (só dentro do JWT, base64).
    expect(json_encode($res->inertiaPage()['props']))->not->toContain($session->room_name);
});

it('bloqueia com 403 quando a feature flag está desligada', function () {
    fakeLiveKit();
    config(['features.live_enabled' => false]);
    $performer = livePerformer();
    openLive($performer->performerProfile);
    $member = liveMember();

    $this->actingAs($member)
        ->getJson(route('live.show', $performer->performerProfile->slug))
        ->assertForbidden();
    $this->actingAs($performer)
        ->postJson(route('performer.live.start'))
        ->assertForbidden();
});

it('guest sem sessão é barrado no refresh (redirect de login — porta web)', function () {
    fakeLiveKit();
    $performer = livePerformer();
    openLive($performer->performerProfile);

    // Rota WEB (sessão), não api/*: o `auth` redireciona o guest ao login em vez
    // de 401 (regra das "duas portas de auth" do CLAUDE.md — fora de api/* a
    // exceção não vira JSON). O que importa: o guest NÃO recebe token.
    $this->postJson(route('live.refresh', $performer->performerProfile->slug))
        ->assertRedirect(route('login'));
});

it('performer sem live ativa devolve 404 (sala inexistente)', function () {
    fakeLiveKit();
    $performer = livePerformer(); // nunca iniciou live
    $member = liveMember();

    $this->actingAs($member)
        ->get(route('live.show', $performer->performerProfile->slug))
        ->assertNotFound();
});
