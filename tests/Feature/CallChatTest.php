<?php

use App\Events\CallMessageSent;
use App\Models\CallSession;
use App\Models\User;
use App\Services\DocumentAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Chat de TEXTO da chamada 1:1 (feat/in-call-chat). Mesma política do chat pago:
 * contato PASSA (o par já se vê/fala por vídeo), risco legal e conduta BLOQUEIAM.
 * Privacidade M.13.10: a performer vê o membro só pelo FanAlias; o payload nunca
 * carrega member_id/sender_id. Helpers com prefixo `icc` para não colidir com os
 * globais do PrivateCallTest.
 */
beforeEach(function () {
    config(['features.call_enabled' => true, 'chat_filters.enabled' => true]);
});

function iccPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Estrela',
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 70,
    ]);
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function iccMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function iccActiveCall(User $performer, User $member, string $status = 'active'): CallSession
{
    $s = new CallSession([
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'room_name' => 'call-'.Str::random(12),
        'price_per_minute' => 10,
    ]);
    $s->forceFill([
        'type' => CallSession::TYPE_PRIVATE,
        'status' => $status,
        'started_at' => now(),
    ])->save();

    return $s;
}

it('membro envia mensagem: persiste e difunde com FanAlias (sem member_id)', function () {
    Event::fake([CallMessageSent::class]);
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $res = $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'oi tudo bem?']);

    $res->assertStatus(201)
        ->assertJsonPath('message.is_performer', false)
        ->assertJsonPath('message.body', 'oi tudo bem?');
    expect($res->json('message.label'))->toStartWith('Fã #');
    expect($res->json('message'))->not->toHaveKey('member_id');
    expect($res->json('message'))->not->toHaveKey('sender_id');

    $this->assertDatabaseHas('call_messages', [
        'call_session_id' => $call->id, 'sender_id' => $mem->id, 'body' => 'oi tudo bem?',
    ]);

    Event::assertDispatched(CallMessageSent::class, fn ($e) => $e->callId === $call->id
        && $e->isPerformer === false
        && str_starts_with($e->label, 'Fã #'));
});

it('performer envia: label é o stage_name, is_performer true', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($perf)->postJson(route('call.chat.store', $call->id), ['body' => 'oi amor'])
        ->assertStatus(201)
        ->assertJsonPath('message.is_performer', true)
        ->assertJsonPath('message.label', 'Estrela');
});

it('terceiro não participa: 404 no envio e no histórico', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);
    $outro = iccMember();

    $this->actingAs($outro)->postJson(route('call.chat.store', $call->id), ['body' => 'oi'])->assertStatus(404);
    $this->actingAs($outro)->getJson(route('call.chat.index', $call->id))->assertStatus(404);
});

it('rejeita corpo acima de 500 caracteres', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => str_repeat('a', 501)])
        ->assertStatus(422);
});

it('troca de contato PASSA (mesma política do chat pago)', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'me chama no whatsapp'])
        ->assertStatus(201);
});

it('bloqueia programa pago (TIPO 1) com 422 e não grava', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'faço programa completo'])
        ->assertStatus(422);
    $this->assertDatabaseCount('call_messages', 0);
});

it('bloqueia conduta abusiva (TIPO 2) com 422', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'vou te matar'])
        ->assertStatus(422);
});

it('chamada não-ativa recusa envio com 409', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem, 'ended');

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'oi'])
        ->assertStatus(409);
});

it('histórico volta em ordem com label, sem sender_id', function () {
    $perf = iccPerformer();
    $mem = iccMember();
    $call = iccActiveCall($perf, $mem);

    $this->actingAs($mem)->postJson(route('call.chat.store', $call->id), ['body' => 'primeira']);
    $this->actingAs($perf)->postJson(route('call.chat.store', $call->id), ['body' => 'segunda']);

    $res = $this->actingAs($perf)->getJson(route('call.chat.index', $call->id));
    $res->assertStatus(200)
        ->assertJsonPath('messages.0.body', 'primeira')
        ->assertJsonPath('messages.1.body', 'segunda')
        ->assertJsonPath('messages.1.is_performer', true);
    expect($res->json('messages.0.label'))->toStartWith('Fã #');
    expect($res->json('messages.0'))->not->toHaveKey('sender_id');
});

it('evento transmite em call.{id} como call.chat, payload não-sensível', function () {
    $e = new CallMessageSent(42, 7, 'Fã #0001', 'oi', false);

    expect($e->broadcastOn()[0]->name)->toContain('call.42');
    expect($e->broadcastAs())->toBe('call.chat');
    expect($e->broadcastWith())->toBe(['id' => 7, 'label' => 'Fã #0001', 'body' => 'oi', 'is_performer' => false]);
});

it('PrivateCall tem o painel de chat ligado ao canal da chamada', function () {
    $src = file_get_contents(resource_path('js/Components/PrivateCall.vue'));

    expect($src)->toContain('window.Echo.private(`call.${props.callId}`)');
    expect($src)->toContain("chatChannel.listen('.call.chat', onChatMessage)");
    expect($src)->toContain("route('call.chat.store', props.callId)");
    expect($src)->toContain("route('call.chat.index', props.callId)");
    expect($src)->toContain('function toggleChat');
    expect($src)->toContain('function isMine');
    expect($src)->toContain('@click="toggleChat"');
});
