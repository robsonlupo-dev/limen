<?php

use App\Events\LiveChatSent;
use App\Models\LiveChatMessage;
use App\Models\LiveChatMute;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * feat/live-room-console — console da performer + chat da sala. Antes a live subia
 * "no escuro"; agora a performer tem espectadores ao vivo, ganho acumulado, feed e
 * chat, e os dois lados conversam (grátis, filtrado, moderável).
 *
 * Helpers PRÓPRIOS (prefixo lc*) para o arquivo rodar isolado — mesma disciplina do
 * PublicLiveTest de não depender de função definida em outro arquivo de teste.
 */
beforeEach(function () {
    config([
        'livekit.api_key' => 'test-key',
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'wss://livekit.test',
        'features.live_enabled' => true,
    ]);
});

function lcFakeLiveKit(array $overrides = []): MockInterface
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

function lcPerformer(): User
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

function lcMember(int $balance = 0): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

function lcOpenLive(PerformerProfile $profile): LiveSession
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

// ── Console: espectadores ao vivo + ganho acumulado ──────────────────────────

it('console sem live ativa devolve is_live:false', function () {
    lcFakeLiveKit();
    $performer = lcPerformer();

    $this->actingAs($performer)->getJson(route('performer.live.console'))
        ->assertOk()
        ->assertJson(['is_live' => false]);
});

it('console ao vivo: os espectadores refletem a presença REAL (listParticipants − a performer)', function () {
    // 3 participantes na sala (a performer publisher + 2 espectadores) → conta 2.
    lcFakeLiveKit(['listParticipants' => ['pub', 'v1', 'v2']]);
    $performer = lcPerformer();
    lcOpenLive($performer->performerProfile);

    $this->actingAs($performer)->getJson(route('performer.live.console'))
        ->assertOk()
        ->assertJson(['is_live' => true, 'viewers' => 2]);
});

it('o ganho acumulado do console BATE com o ledger (gorjeta + presente da live)', function () {
    lcFakeLiveKit();
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);

    $policy = app(TokenCreditPolicy::class);
    // Gorjeta bruta 3 → 80% = 2,4000; presente bruto 40 → 80% = 32,0000. Soma 34,4000.
    $policy->creditWithSplit($performer, 3, 'tip', 'tip_credit', null, null, 'Gorjeta recebida de Fã #0001');
    $policy->creditWithSplit($performer, 40, 'gift', 'gift_credit', null, null, 'Presente recebido de Fã #0001');

    $res = $this->actingAs($performer)->getJson(route('performer.live.console'))->assertOk();

    expect((string) $res->json('earned'))->toBe('34.4000');
    expect(app(\App\Services\LiveSessionService::class)->earnedThisLive($session->fresh()))->toBe('34.4000');
});

it('o ganho da live IGNORA créditos anteriores ao início da transmissão', function () {
    lcFakeLiveKit();
    $performer = lcPerformer();
    $policy = app(TokenCreditPolicy::class);

    // Crédito de ONTEM (antes desta live) não entra.
    $this->travelTo(now()->subDay());
    $policy->creditWithSplit($performer, 100, 'tip', 'tip_credit', null, null, 'Gorjeta antiga');
    $this->travelBack();

    $session = lcOpenLive($performer->performerProfile); // started_at = agora
    $policy->creditWithSplit($performer, 40, 'gift', 'gift_credit', null, null, 'Presente da live');

    expect(app(\App\Services\LiveSessionService::class)->earnedThisLive($session))->toBe(32);
});

// ── Chat da sala: envio, filtro, gratuidade ──────────────────────────────────

it('o membro envia mensagem no chat: persiste, difunde por FanAlias e é GRÁTIS', function () {
    Event::fake([LiveChatSent::class]);
    lcFakeLiveKit();
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);

    $this->actingAs($member)
        ->postJson(route('live.chat', $performer->performerProfile->slug), ['body' => 'oi, tudo bem?'])
        ->assertOk();

    $msg = LiveChatMessage::where('live_session_id', $session->id)->first();
    expect($msg)->not->toBeNull()
        ->and($msg->is_performer)->toBeFalse()
        ->and($msg->body)->toBe('oi, tudo bem?');

    // Difunde com o FanAlias label do membro (nunca o id/nome).
    $label = FanAlias::label($performer->performerProfile->id, $member->id);
    Event::assertDispatched(LiveChatSent::class, fn ($e) => $e->label === $label && $e->body === 'oi, tudo bem?' && $e->isPerformer === false);

    // Chat é grátis: o saldo do membro não muda.
    expect(app(TokenService::class)->balance($member->fresh()))->toBe(50);
});

it('a performer responde no chat (is_performer, pelo stage_name)', function () {
    Event::fake([LiveChatSent::class]);
    lcFakeLiveKit();
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);

    $this->actingAs($performer)
        ->postJson(route('performer.live.chat'), ['body' => 'boa noite a todos'])
        ->assertOk();

    $msg = LiveChatMessage::where('live_session_id', $session->id)->first();
    expect($msg->is_performer)->toBeTrue();
    Event::assertDispatched(LiveChatSent::class, fn ($e) => $e->isPerformer === true && $e->label === $performer->performerProfile->stage_name);
});

it('mensagem que viola o filtro (risco legal) é barrada: sem linha e sem broadcast', function () {
    Event::fake([LiveChatSent::class]);
    lcFakeLiveKit();
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);

    $this->actingAs($member)
        ->postJson(route('live.chat', $performer->performerProfile->slug), ['body' => 'faço programa completo'])
        ->assertStatus(422)
        ->assertJson(['reason' => 'content_blocked']);

    expect(LiveChatMessage::where('live_session_id', $session->id)->count())->toBe(0);
    Event::assertNotDispatched(LiveChatSent::class);
});

it('mensagem de conduta (ameaça) é barrada com conduct_blocked', function () {
    lcFakeLiveKit();
    $performer = lcPerformer();
    lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);

    $this->actingAs($member)
        ->postJson(route('live.chat', $performer->performerProfile->slug), ['body' => 'vou te matar'])
        ->assertStatus(422)
        ->assertJson(['reason' => 'conduct_blocked']);
});

// ── Moderação: silenciar/remover ─────────────────────────────────────────────

it('a performer silencia o autor de uma mensagem e ele NÃO envia mais', function () {
    lcFakeLiveKit(['removeParticipant' => null]);
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);
    $slug = $performer->performerProfile->slug;

    $sent = $this->actingAs($member)
        ->postJson(route('live.chat', $slug), ['body' => 'primeira mensagem'])
        ->assertOk();
    $messageId = $sent->json('id');

    // A performer silencia clicando na mensagem (o servidor resolve o autor).
    $this->actingAs($performer)
        ->postJson(route('performer.live.mute'), ['message_id' => $messageId])
        ->assertOk()
        ->assertJson(['muted' => FanAlias::label($performer->performerProfile->id, $member->id)]);

    expect(LiveChatMute::where('live_session_id', $session->id)->where('member_id', $member->id)->exists())->toBeTrue();

    // Nova tentativa do membro silenciado → 403.
    $this->actingAs($member)
        ->postJson(route('live.chat', $slug), ['body' => 'segunda mensagem'])
        ->assertStatus(403)
        ->assertJson(['reason' => 'muted']);
});

it('o membro removido não reabre a sala (show 403) nem reautoriza (refresh 403)', function () {
    lcFakeLiveKit(['removeParticipant' => null]);
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);
    $slug = $performer->performerProfile->slug;

    LiveChatMute::create(['live_session_id' => $session->id, 'member_id' => $member->id]);

    $this->actingAs($member)->get(route('live.show', $slug))->assertForbidden();
    $this->actingAs($member)->postJson(route('live.refresh', $slug))->assertForbidden();
});

it('silenciar uma mensagem inexistente ou da própria performer não vaza (409)', function () {
    lcFakeLiveKit(['removeParticipant' => null]);
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);

    // A própria fala da performer não é silenciável.
    $own = LiveChatMessage::create([
        'live_session_id' => $session->id, 'sender_id' => $performer->id, 'is_performer' => true, 'body' => 'oi',
    ]);

    $this->actingAs($performer)->postJson(route('performer.live.mute'), ['message_id' => $own->id])->assertStatus(409);
    $this->actingAs($performer)->postJson(route('performer.live.mute'), ['message_id' => 999999])->assertStatus(409);
});

// ── Espectadores (lado do membro) + encerramento ─────────────────────────────

it('o membro pola a contagem ao vivo de espectadores', function () {
    lcFakeLiveKit(['listParticipants' => ['pub', 'v1', 'v2', 'v3']]);
    $performer = lcPerformer();
    lcOpenLive($performer->performerProfile);
    $member = lcMember();

    $this->actingAs($member)
        ->getJson(route('live.viewer-count', $performer->performerProfile->slug))
        ->assertOk()
        ->assertJson(['viewers' => 3]);
});

it('o chat da live é efêmero: encerrar a transmissão apaga mensagens e mutes', function () {
    lcFakeLiveKit();
    $performer = lcPerformer();
    $session = lcOpenLive($performer->performerProfile);
    $member = lcMember(balance: 50);

    $this->actingAs($member)->postJson(route('live.chat', $performer->performerProfile->slug), ['body' => 'oi'])->assertOk();
    LiveChatMute::create(['live_session_id' => $session->id, 'member_id' => $member->id]);

    $this->actingAs($performer)->postJson(route('performer.live.stop'))->assertOk();

    expect(LiveChatMessage::where('live_session_id', $session->id)->count())->toBe(0)
        ->and(LiveChatMute::where('live_session_id', $session->id)->count())->toBe(0);
});
