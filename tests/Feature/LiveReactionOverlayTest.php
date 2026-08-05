<?php

use App\Events\LiveReaction;
use App\Models\Gift;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\GiftService;
use App\Services\TipService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * PR #142 (Sprint 15) — animação de gorjeta/presente na live (prova social). O
 * TipService/GiftService disparam o evento broadcast LiveReaction SÓ quando a
 * transação é nova E há uma live pública ativa (live_sessions status=live). O
 * payload é não-sensível: FanAlias label, valor e tipo — nunca member_id/saldo/tier.
 */
function reactPerformer(): User
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

    return $user->fresh();
}

function reactMember(int $balance = 200): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    app(TokenService::class)->credit($user, $balance, 'purchase');

    return $user;
}

function goLiveNow(PerformerProfile $profile): LiveSession
{
    return LiveSession::create([
        'performer_profile_id' => $profile->id,
        'room_name' => 'live-'.$profile->id.'-'.bin2hex(random_bytes(4)),
        'status' => 'live',
        'viewer_count' => 0,
        'started_at' => now(),
    ]);
}

// ── Dispara durante a live ───────────────────────────────────────────────────

it('gorjeta durante uma live ativa dispara o evento LiveReaction', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $profile = $performer->performerProfile;
    goLiveNow($profile);
    $member = reactMember();

    app(TipService::class)->send($member, $profile, 50, (string) Str::uuid());

    Event::assertDispatched(LiveReaction::class, function ($e) use ($profile, $member) {
        return $e->performerSlug === $profile->slug
            && $e->type === 'tip'
            && $e->giftSlug === null
            && $e->amountTokens === 50
            && $e->fanAliasLabel === FanAlias::label($profile->id, $member->id);
    });
});

it('presente durante uma live ativa dispara o evento com gift_slug e amount corretos', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $profile = $performer->performerProfile;
    goLiveNow($profile);
    $member = reactMember();
    $gift = Gift::create(['name' => 'Champagne', 'slug' => 'champagne', 'price_tokens' => 40, 'active' => true]);

    app(GiftService::class)->send($member, $profile, $gift, (string) Str::uuid());

    Event::assertDispatched(LiveReaction::class, function ($e) use ($profile, $member) {
        return $e->performerSlug === $profile->slug
            && $e->type === 'gift'
            && $e->giftSlug === 'champagne'
            && $e->amountTokens === 40
            && $e->fanAliasLabel === FanAlias::label($profile->id, $member->id);
    });
});

// ── NÃO dispara fora da live ─────────────────────────────────────────────────

it('gorjeta FORA de uma live (perfil normal) NÃO dispara o evento', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer(); // sem live_session ativa
    $member = reactMember();

    app(TipService::class)->send($member, $performer->performerProfile, 50, (string) Str::uuid());

    Event::assertNotDispatched(LiveReaction::class);
});

it('presente com a live ENCERRADA (status != live) NÃO dispara o evento', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $session = goLiveNow($performer->performerProfile);
    $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save(); // não é mais 'live'
    $member = reactMember();
    $gift = Gift::create(['name' => 'Rosa', 'slug' => 'rosa', 'price_tokens' => 4, 'active' => true]);

    app(GiftService::class)->send($member, $performer->performerProfile, $gift, (string) Str::uuid());

    Event::assertNotDispatched(LiveReaction::class);
});

// ── Payload não vaza dado sensível ───────────────────────────────────────────

it('o payload broadcast leva fan_alias_label e NUNCA member_id/saldo/tier', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $profile = $performer->performerProfile;
    goLiveNow($profile);
    $member = reactMember(balance: 500);

    app(TipService::class)->send($member, $profile, 100, (string) Str::uuid());

    Event::assertDispatched(LiveReaction::class, function ($e) use ($member) {
        $payload = $e->broadcastWith();
        // Exatamente as 4 chaves esperadas — nada de member_id/balance/tier.
        expect(array_keys($payload))->toEqualCanonicalizing(['type', 'gift_slug', 'amount_tokens', 'fan_alias_label']);
        // Nenhum valor do payload é o id cru do membro.
        expect(json_encode($payload))->not->toContain('"member_id"')
            ->and($payload['fan_alias_label'])->not->toBe((string) $member->id);

        return true;
    });
});

// ── Idempotência: envio duplicado NÃO dobra a animação ───────────────────────

it('gorjeta duplicada (mesma idempotency_key) dispara o evento uma vez só', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $profile = $performer->performerProfile;
    goLiveNow($profile);
    $member = reactMember();
    $key = (string) Str::uuid();

    app(TipService::class)->send($member, $profile, 20, $key);
    app(TipService::class)->send($member, $profile, 20, $key); // retorno idempotente

    Event::assertDispatchedTimes(LiveReaction::class, 1);
});

it('presente duplicado (mesma idempotency_key) dispara o evento uma vez só', function () {
    Event::fake([LiveReaction::class]);
    $performer = reactPerformer();
    $profile = $performer->performerProfile;
    goLiveNow($profile);
    $member = reactMember();
    $gift = Gift::create(['name' => 'Joia', 'slug' => 'joia', 'price_tokens' => 100, 'active' => true]);
    $key = (string) Str::uuid();

    app(GiftService::class)->send($member, $profile, $gift, $key);
    app(GiftService::class)->send($member, $profile, $gift, $key);

    Event::assertDispatchedTimes(LiveReaction::class, 1);
});

// ── Autorização do canal privado live.{slug} ─────────────────────────────────
//
// A regra vive em routes/channels.php (dona do perfil OU consumer ativo; perfil
// inexistente nega). NÃO é testável aqui: BROADCAST_CONNECTION=null no phpunit.xml,
// e o broadcaster null aprova o /broadcasting/auth sem consultar o callback — o
// projeto inteiro não tem teste de canal por isso (conversation.{id}/user.{id}
// idem). A regra é a mesma disciplina desses canais e roda com o Reverb real.
//
// Invocamos o callback registrado DIRETAMENTE para exercitar a lógica.

it('a regra do canal live.{slug} libera dona e membro ativo, nega pending_kyc e slug inexistente', function () {
    $performer = reactPerformer();
    $slug = $performer->performerProfile->slug;
    $member = reactMember();
    $pending = User::factory()->create(['role' => 'consumer', 'status' => 'pending_kyc']);

    // Resolve o callback registrado para 'live.{slug}' no broadcaster.
    $broadcaster = app(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
    $channels = (new ReflectionClass($broadcaster))->getProperty('channels');
    $channels->setAccessible(true);
    $callback = $channels->getValue($broadcaster)['live.{slug}'];

    expect($callback($performer, $slug))->toBeTruthy()   // dona do perfil
        ->and($callback($member, $slug))->toBeTruthy()    // membro verificado
        ->and($callback($pending, $slug))->toBeFalsy()    // pending_kyc: fora
        ->and($callback($member, 'nao-existe-xyz'))->toBeFalsy(); // slug inexistente
});
