<?php

use App\Models\CallSession;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\CallService;
use App\Services\DocumentAcceptanceService;
use App\Services\LiveKitService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * fix/live-call-flow-states — os 4 bugs do fluxo da chamada privada a partir da live:
 *  1. vídeo preto (handoff de câmera) — testado por FONTE (runtime de navegador);
 *  2. botão "Recusar" — testado por FONTE (estilo/clicável);
 *  3. pedido expira e o membro fica preso — servidor: expiração não move token e
 *     NÃO trava novos pedidos (reconcileMemberStale na leitura);
 *  4. estados travados ao encerrar a live — testado por FONTE (limpeza + redirect).
 *
 * MAIS o invariante do relógio: o minuto 1 só é cobrado quando o vídeo conecta (o 1º
 * heartbeat), nunca no aceite/recusa/expiração. Helpers `lcf*` para rodar isolado.
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

function lcfKit(): MockInterface
{
    $lk = Mockery::mock(LiveKitService::class)->makePartial();
    $lk->shouldReceive('createRoom', 'deleteRoom')->andReturnNull()->byDefault();
    $lk->shouldReceive('roomExists')->andReturn(true)->byDefault();
    $lk->shouldReceive('listParticipants')->andReturn([])->byDefault();
    app()->instance(LiveKitService::class, $lk);

    return $lk;
}

function lcfPerformer(int $price = 10): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres', 'worlds' => ['mulheres'],
        'is_verified' => true, 'level' => 'iniciante', 'split_pct' => 65,
    ]);
    $user->performerProfile->forceFill(['call_price_per_minute' => $price])->save();
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function lcfMember(int $balance = 100): User
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

function lcfLedgerMoved(): int
{
    return TokenLedger::whereIn('entry_type', ['spend_call', 'call_credit'])->count();
}

// ── Bug 3 (servidor): recusa/expiração não movem token e liberam ─────────────

it('RECUSAR não move token e libera a performer para um novo pedido', function () {
    lcfKit();
    $performer = lcfPerformer(10);
    $member = lcfMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated()->json('call_id');
    $this->actingAs($performer)->postJson(route('call.decline', $callId))->assertOk();

    // Nenhum token se moveu; o membro segue com 100.
    expect(lcfLedgerMoved())->toBe(0)
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(100)
        ->and(CallSession::find($callId)->status)->toBe('declined');

    // A performer está LIVRE: um novo pedido é aceito (não 409).
    $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated();
});

it('pedido EXPIRADO com outra performer NÃO trava um novo pedido (destrava na leitura, sem cron)', function () {
    lcfKit();
    $member = lcfMember(100);
    $perfA = lcfPerformer(10);
    $perfB = lcfPerformer(10);

    $callA = $this->actingAs($member)->postJson(route('call.request', $perfA->performerProfile->id))
        ->assertCreated()->json('call_id');

    // Vence o TTL do pending SEM rodar o cron: só o read-path do próximo request
    // deve destravar (era o "pedido eterno" que travava tudo).
    $this->travel(CallSession::PENDING_TTL_SECONDS + 5)->seconds();

    $this->actingAs($member)->postJson(route('call.request', $perfB->performerProfile->id))
        ->assertCreated(); // NÃO 409

    expect(CallSession::find($callA)->status)->toBe('expired') // reconciliado na leitura
        ->and(lcfLedgerMoved())->toBe(0);                       // expiração não cobra
});

it('ACEITA mas o membro nunca conecta: o reaper encerra SEM cobrar e não deixa preso', function () {
    lcfKit();
    $performer = lcfPerformer(10);
    $member = lcfMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated()->json('call_id');
    $this->actingAs($performer)->postJson(route('call.accept', $callId))->assertOk();

    // Aceita, sala pronta, mas SEM relógio/cobrança até o vídeo conectar.
    $call = CallSession::find($callId);
    expect($call->status)->toBe('active')
        ->and($call->started_at)->toBeNull()
        ->and($call->minutes_billed)->toBe(0)
        ->and(lcfLedgerMoved())->toBe(0);

    // O membro nunca conecta (nenhum heartbeat). O reaper encerra sem cobrar.
    $this->travel(200)->seconds();
    app(CallService::class)->reapStaleSessions();

    expect(CallSession::find($callId)->status)->toBe('ended')
        ->and(lcfLedgerMoved())->toBe(0)
        ->and(app(TokenService::class)->balance($member->fresh()))->toBe(100);

    // E o membro NÃO ficou preso: pode pedir de novo.
    $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated();
});

it('o relógio começa no CONNECT: aceite não cobra, o 1º heartbeat cobra o minuto 1', function () {
    lcfKit();
    $performer = lcfPerformer(10);
    $member = lcfMember(100);

    $callId = $this->actingAs($member)->postJson(route('call.request', $performer->performerProfile->id))
        ->assertCreated()->json('call_id');
    $this->actingAs($performer)->postJson(route('call.accept', $callId))->assertOk();
    expect(lcfLedgerMoved())->toBe(0); // aceite não move token

    // Vídeo conecta → 1º heartbeat → minuto 1 (débito 10 + crédito 7).
    $this->actingAs($member)->postJson(route('call.heartbeat', $callId))->assertOk();
    expect((int) TokenLedger::where('entry_type', 'spend_call')->sum('amount'))->toBe(-10)
        ->and(CallSession::find($callId)->minutes_billed)->toBe(1)
        ->and(CallSession::find($callId)->started_at)->not->toBeNull();
});

// ── Bugs 1, 2, 4 (frontend): testados por FONTE (runtime de navegador) ────────

it('bug 1: PrivateCall anexa a faixa REMOTA ao vídeo remoto e a LOCAL ao local, com retry no handoff', function () {
    $src = file_get_contents(resource_path('js/Components/PrivateCall.vue'));

    // O alvo do attach depende de participantIsLocal — remoto vai ao remoteVideo
    // (o que o membro vê da performer), local ao localVideo. A faixa remota nunca
    // some num elemento desmontado (os <video> não têm v-if de status).
    expect($src)->toContain('participantIsLocal ? localVideo.value : remoteVideo.value')
        // Retry na aquisição da câmera (mesmo device que a sala pública liberou).
        ->and($src)->toContain('enableLocalMedia')
        ->and($src)->toContain('setCameraEnabled(true)');
});

it('bug 2: o card de chamada recebida tem Recusar clicável e visível (fim do tema claro)', function () {
    $src = file_get_contents(resource_path('js/Components/CallIncoming.vue'));

    expect($src)->toContain('@click="decline"')
        ->and($src)->toContain('text-cream')     // texto visível
        ->and($src)->not->toContain('bg-white');  // sem card de tema claro
});

it('bug 3 (front): o LiveViewer solta o membro de "Aguardando" quando o pedido expira', function () {
    $src = file_get_contents(resource_path('js/Components/LiveViewer.vue'));

    expect($src)->toContain('startPendingTimeout')
        ->and($src)->toContain('expires_in_seconds');
});

it('bug 4: ao encerrar a live o LiveViewer limpa os estados de chamada e redireciona ao catálogo', function () {
    $src = file_get_contents(resource_path('js/Components/LiveViewer.vue'));

    expect($src)->toContain('watch(status')
        ->and($src)->toContain('startRedirectCountdown')
        ->and($src)->toContain("router.visit(route('catalog'))")
        ->and($src)->toContain('Voltar ao catálogo agora')
        // Não arrasta quem está NUMA chamada para o catálogo.
        ->and($src)->toContain("callState.value !== 'in-call'");
});
