<?php

use App\Exceptions\FeatureDisabledException;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\LiveKitService;
use App\Services\TokenCreditPolicy;
use App\Support\FanAlias;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Route;

/**
 * PR #138 (Sprint 15) — infra LiveKit: service de rooms/tokens, feature flags,
 * entry_types de live/chamada, e as constantes de cobrança. Sem rota/UI/cobrança
 * (PRs seguintes). Os testes travam o CONTRATO — em especial que a identity do
 * membro NUNCA carrega o id cru (achado 🔴 da revisão de segurança do plano).
 */
beforeEach(function () {
    // Credenciais falsas e determinísticas (o .env do servidor tem as reais; o CI
    // não). O secret assina o JWT localmente — HS256.
    config([
        'livekit.api_key' => 'test-key',
        // HS256 exige chave ≥ 32 bytes (validateHmacKeyLength do firebase/php-jwt);
        // segredos reais do LiveKit são longos. 40 chars aqui.
        'livekit.api_secret' => 'test-secret-0123456789abcdefghijklmnopqr',
        'livekit.url' => 'https://livekit.test',
        // generateToken/createRoom checam a flag internamente; ligamos aqui e
        // testamos o caminho off à parte.
        'features.live_enabled' => true,
        'features.call_enabled' => true,
    ]);
});

function decodeLkToken(string $jwt): object
{
    return JWT::decode($jwt, new Key(config('livekit.api_secret'), 'HS256'));
}

// ── Token: TTL, identity, grants ─────────────────────────────────────────────

it('signs a token with the configured TTL and the given identity', function () {
    $svc = app(LiveKitService::class);
    $room = $svc->liveRoomName(1);

    $jwt = $svc->generateToken($svc->performerIdentity(42), LiveKitService::ROLE_PERFORMER, $room, [
        'canPublish' => true, 'canSubscribe' => false,
    ]);
    $claims = decodeLkToken($jwt);

    expect($claims->exp - $claims->iat)->toBe(300)
        ->and($claims->sub)->toBe('performer:42')
        ->and($claims->video->room)->toBe($room)
        ->and($claims->video->roomJoin)->toBeTrue();
});

it('gives a live performer canPublish and not canSubscribe', function () {
    $svc = app(LiveKitService::class);
    $jwt = $svc->generateToken($svc->performerIdentity(7), LiveKitService::ROLE_PERFORMER, $svc->liveRoomName(7), [
        'canPublish' => true, 'canSubscribe' => false,
    ]);
    $video = decodeLkToken($jwt)->video;

    expect($video->canPublish)->toBeTrue()
        ->and($video->canSubscribe)->toBeFalse();
});

it('gives a live member canSubscribe and not canPublish', function () {
    $svc = app(LiveKitService::class);
    $jwt = $svc->generateToken($svc->liveParticipantIdentity(1, 1), LiveKitService::ROLE_MEMBER, $svc->liveRoomName(7), [
        'canPublish' => false, 'canSubscribe' => true,
    ]);
    $video = decodeLkToken($jwt)->video;

    expect($video->canPublish)->toBeFalse()
        ->and($video->canSubscribe)->toBeTrue();
});

it('gives both call participants canPublish and canSubscribe', function () {
    $svc = app(LiveKitService::class);
    $room = $svc->callRoomName();

    foreach ([$svc->performerIdentity(3), $svc->callMemberIdentity(3, 99)] as $identity) {
        $video = decodeLkToken($svc->generateToken($identity, LiveKitService::ROLE_MEMBER, $room, [
            'canPublish' => true, 'canSubscribe' => true,
        ]))->video;

        expect($video->canPublish)->toBeTrue()
            ->and($video->canSubscribe)->toBeTrue();
    }
});

// ── Identity: NUNCA o id cru do membro (achado 🔴) ───────────────────────────

it('never puts the raw member id in the call identity — only the FanAlias handle', function () {
    $svc = app(LiveKitService::class);
    $memberId = 12345;

    $identity = $svc->callMemberIdentity(3, $memberId);

    expect($identity)->toBe('member:'.FanAlias::handle(3, $memberId))
        ->and($identity)->toMatch('/^member:[0-9a-f]{16}$/')
        ->and($identity)->not->toContain((string) $memberId);
});

it('derives an opaque live-viewer identity that is stable per live, distinct across lives, and hides the member id', function () {
    $svc = app(LiveKitService::class);

    // Estável DENTRO da mesma live (session 10, member 777): o refresh reemite a
    // MESMA identity — o membro não vira um participante novo a cada renovação.
    $a1 = $svc->liveParticipantIdentity(10, 777);
    $a2 = $svc->liveParticipantIdentity(10, 777);
    // Distinta ENTRE lives (session 11): some a frequência de retorno do "fã".
    $b = $svc->liveParticipantIdentity(11, 777);

    expect($a1)->toMatch('/^lv_[0-9a-f]{16}$/')
        ->and($a1)->toBe($a2)                 // estável no refresh
        ->and($a1)->not->toBe($b)             // não correlaciona entre lives
        ->and($a1)->not->toContain('777');    // nunca o id cru do membro
});

// ── Room names imprevisíveis ─────────────────────────────────────────────────

it('builds unpredictable room names with random hex, never sequential', function () {
    $svc = app(LiveKitService::class);

    expect($svc->liveRoomName(9))->toMatch('/^live-9-[0-9a-f]{8}$/')
        ->and($svc->callRoomName())->toMatch('/^call-[0-9a-f]{12}$/')
        ->and($svc->groupRoomName(9))->toMatch('/^group-9-[0-9a-f]{8}$/')
        ->and($svc->liveRoomName(9))->not->toBe($svc->liveRoomName(9));
});

// ── Feature flag: middleware + backstop do service ───────────────────────────

it('returns 403 from the feature middleware when the flag is off', function () {
    Route::get('/_test/live-gate', fn () => 'ok')->middleware('feature:live');

    config(['features.live_enabled' => false]);
    $this->get('/_test/live-gate')->assertForbidden();
});

it('allows the request through the feature middleware when the flag is on', function () {
    Route::get('/_test/live-gate', fn () => 'ok')->middleware('feature:live');

    config(['features.live_enabled' => true]);
    $this->get('/_test/live-gate')->assertOk();
});

it('refuses to emit a token when the feature is disabled (service backstop)', function () {
    $svc = app(LiveKitService::class);
    $room = $svc->liveRoomName(1);
    config(['features.live_enabled' => false]);

    expect(fn () => $svc->generateToken($svc->liveParticipantIdentity(1, 1), LiveKitService::ROLE_MEMBER, $room, [
        'canSubscribe' => true,
    ]))->toThrow(FeatureDisabledException::class);
});

it('refuses to create a room when the feature is disabled (service backstop)', function () {
    $svc = app(LiveKitService::class);
    $room = $svc->callRoomName();
    config(['features.call_enabled' => false]);

    // Barra ANTES de tocar a rede — nenhum RoomServiceClient é instanciado.
    expect(fn () => $svc->createRoom($room, 2))->toThrow(FeatureDisabledException::class);
});

// ── Ledger: entry_types novos existem e nunca respeitam teto ──────────────────

it('accepts the new live/call entry types in the ledger enum', function () {
    $user = User::factory()->create();
    $wallet = TokenWallet::create(['user_id' => $user->id, 'balance' => 0]);

    foreach (['spend_live', 'live_credit', 'spend_call', 'call_credit'] as $type) {
        TokenLedger::create([
            'wallet_id' => $wallet->id,
            'entry_type' => $type,
            'amount' => 5,
            'balance_after' => 5,
        ]);
    }

    expect(TokenLedger::where('wallet_id', $wallet->id)
        ->whereIn('entry_type', ['spend_live', 'live_credit', 'spend_call', 'call_credit'])
        ->count())->toBe(4);
});

it('keeps live_credit and call_credit in the payout earning allowlist', function () {
    $allow = config('monetization.payout.earning_entry_types');

    expect($allow)->toContain('live_credit')->toContain('call_credit');
});

it('never lets live_credit or call_credit respect the cap (M.13.9)', function () {
    $policy = app(TokenCreditPolicy::class);

    expect($policy->respectsCap('live_credit'))->toBeFalse()
        ->and($policy->respectsCap('call_credit'))->toBeFalse()
        ->and($policy->respectsCap('purchase'))->toBeTrue(); // sanidade
});

// ── Config lida do .env + piso/split ─────────────────────────────────────────

it('reads livekit config with the expected defaults', function () {
    expect(config('livekit.token_ttl'))->toBe(300)
        ->and(config('livekit.max_participants_live'))->toBe(50)
        ->and(config('livekit.max_participants_call'))->toBe(2)
        ->and(config('livekit.max_participants_group'))->toBe(10)
        ->and(array_key_exists('api_secret', config('livekit')))->toBeTrue()
        ->and(config('features.live_enabled'))->toBeBool()
        ->and(config('features.call_enabled'))->toBeBool();
});

it('enforces the 5-token/minute floor and keeps the live split mirror in sync', function () {
    expect(config('monetization.call_min_price_per_minute'))->toBe(5)
        ->and(config('monetization.call_price_step'))->toBe(5)
        // O espelho de exibição casa com a autoridade do ledger (split_rates.live).
        ->and(config('monetization.live_split_rate'))->toBe(config('monetization.split_rates.live.rate'));
});
