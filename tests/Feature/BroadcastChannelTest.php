<?php

use App\Models\BroadcastMessage;
use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\BroadcastService;

/**
 * Canal de transmissão da performer (roadmap social, Onda 2).
 *
 * Eixos: (1) publicar é da performer, filtrado anti-contato, com teto diário;
 * (2) o membro só vê broadcasts de quem SEGUE e está de pé; (3) o badge de
 * não-vistos casa com a aba e zera ao abrir; (4) nada de membro atravessa (a
 * entrega é derivada de "seguir"). Helpers com prefixo `bc` (broadcast channel).
 */

// ─── Fixtures ────────────────────────────────────────────────────────────────

function bcPerformer(string $stage = 'Bea', bool $verified = true, string $status = 'active'): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);

    return $user->performerProfile()->create([
        'stage_name' => $stage,
        'slug' => PerformerProfile::generateSlug($stage),
        'bio' => 'Bio',
        'category' => 'mulheres',
        'is_verified' => $verified,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function bcMember(): User
{
    return User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ])->fresh();
}

function bcFollow(User $member, PerformerProfile $profile): void
{
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
}

function bcSend(PerformerProfile $profile, string $body = 'Oi, pessoal!'): BroadcastMessage
{
    return app(BroadcastService::class)->send($profile, $body);
}

// ─── Publicar (performer) ─────────────────────────────────────────────────────

it('a performer publica um broadcast', function () {
    $profile = bcPerformer();

    $this->actingAs($profile->user)
        ->postJson(route('performer.broadcasts.store'), ['body' => 'Novidade nova saindo hoje!'])
        ->assertCreated()
        ->assertJsonPath('broadcast.body', 'Novidade nova saindo hoje!');

    expect(BroadcastMessage::where('performer_profile_id', $profile->id)->count())->toBe(1);
});

it('aplica o filtro anti-contato no texto do broadcast (422)', function () {
    $profile = bcPerformer();

    // Vazar telefone p/ todos os seguidores de graça é o furo que o filtro fecha.
    $this->actingAs($profile->user)
        ->postJson(route('performer.broadcasts.store'), ['body' => 'me chama no zap 11 98888-7777'])
        ->assertStatus(422);

    expect(BroadcastMessage::count())->toBe(0);
});

it('respeita o teto diário de broadcasts (422 rate_limited)', function () {
    config()->set('broadcast.max_per_day', 2);
    $profile = bcPerformer();

    bcSend($profile, 'um');
    bcSend($profile, 'dois');

    $this->actingAs($profile->user)
        ->postJson(route('performer.broadcasts.store'), ['body' => 'três'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'rate_limited');

    expect(BroadcastMessage::count())->toBe(2);
});

it('membro não pode publicar broadcast (403)', function () {
    $member = bcMember();

    $this->actingAs($member)
        ->postJson(route('performer.broadcasts.store'), ['body' => 'oi'])
        ->assertForbidden();
});

// ─── Feed do membro ───────────────────────────────────────────────────────────

it('mostra ao membro só broadcasts de quem ele segue', function () {
    $seguida = bcPerformer('Ana');
    $naoSeguida = bcPerformer('Bia');
    $member = bcMember();
    bcFollow($member, $seguida);

    bcSend($seguida, 'da seguida');
    bcSend($naoSeguida, 'da não-seguida');

    $feed = app(BroadcastService::class)->feedForMember($member->fresh());

    expect($feed)->toHaveCount(1)
        ->and($feed->first()['body'])->toBe('da seguida')
        ->and($feed->first()['performer']['stage_name'])->toBe('Ana');
});

it('não mostra broadcast de performer suspensa ou não verificada', function () {
    $suspensa = bcPerformer('Sus', status: 'suspended');
    $emKyc = bcPerformer('Kyc', verified: false);
    $member = bcMember();
    bcFollow($member, $suspensa);
    bcFollow($member, $emKyc);

    bcSend($suspensa, 'suspensa');
    bcSend($emKyc, 'em kyc');

    expect(app(BroadcastService::class)->feedForMember($member->fresh()))->toHaveCount(0);
});

it('a aba Canais não expõe id de membro', function () {
    $profile = bcPerformer();
    $member = bcMember();
    bcFollow($member, $profile);
    bcSend($profile, 'transmissão');

    $content = $this->actingAs($member->fresh())
        ->get(route('consumer.channels.index'))
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain('member_id')
        ->not->toContain('broadcasts_seen_at');
});

// ─── Badge de não-vistos ──────────────────────────────────────────────────────

it('conta broadcasts não vistos e zera ao abrir a aba', function () {
    $profile = bcPerformer();
    $member = bcMember();
    bcFollow($member, $profile);
    bcSend($profile, 'um');
    bcSend($profile, 'dois');

    $svc = app(BroadcastService::class);
    expect($svc->unseenCountForMember($member->fresh()))->toBe(2);

    // Abrir a aba assenta o watermark → badge zera.
    $this->actingAs($member->fresh())->get(route('consumer.channels.index'))->assertOk();

    expect($svc->unseenCountForMember($member->fresh()))->toBe(0);
});

it('conta só o que chegou depois do watermark', function () {
    $profile = bcPerformer();
    $member = bcMember();
    bcFollow($member, $profile);

    bcSend($profile, 'antigo');
    app(BroadcastService::class)->markSeenForMember($member->fresh());

    // O relógio anda: o watermark e o timestamp são de segundo, então sem avançar
    // o "novo" cairia no mesmo segundo do marcado e o `>` não o contaria (é
    // artefato do teste; na vida real o broadcast chega minutos/horas depois).
    $this->travel(1)->minute();

    // Um novo depois de ver: conta 1.
    bcSend($profile, 'novo');

    expect(app(BroadcastService::class)->unseenCountForMember($member->fresh()))->toBe(1);
});

it('o badge não conta broadcast de quem o membro não segue', function () {
    $naoSeguida = bcPerformer();
    $member = bcMember();
    bcSend($naoSeguida, 'ninguém segue');

    expect(app(BroadcastService::class)->unseenCountForMember($member->fresh()))->toBe(0);
});
