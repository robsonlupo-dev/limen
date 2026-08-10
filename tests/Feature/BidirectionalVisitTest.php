<?php

use App\Models\MemberProfileVisit;
use App\Models\PerformerProfile;
use App\Models\ProfileVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Visitas bidirecionais (A.0.4) — o SENTIDO INVERSO de ProfileVisit: a performer
 * abre o perfil de um membro no catálogo dela, e o membro vê "quem visitou seu
 * perfil". Detalhe no CLAUDE.md, § "Visitas bidirecionais".
 *
 * O que estes testes travam:
 *  - performer→membro é COLETADO (record novo, dedup 30min), com o alvo resolvido
 *    contra os membros visíveis (anti-oráculo, mesma fonte da lista);
 *  - o membro vê a IDENTIDADE PÚBLICA da performer (nome/slug/avatar), sem FanAlias
 *    e sem paywall — assimetria deliberada, porque performer é pública;
 *  - o sentido ANTIGO (membro→performer) fica intocado: FanAlias, Ghost Mode,
 *    Modo Discreto, piso e k continuam valendo;
 *  - a performer NUNCA vê PII do membro em nenhuma superfície de visita;
 *  - GC de 7 dias e Hard Delete nos dois sentidos.
 *
 * Helpers locais (prefixo bv*) para o arquivo ser autossuficiente.
 */

function bvPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
        'avatar_path' => 'avatars/x.jpg',
    ]);

    return $user->fresh();
}

function bvMember(array $attrs = [], ?string $circleSlug = null): User
{
    $member = User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
    ], $attrs));

    if ($circleSlug !== null) {
        Subscription::factory()->circle($circleSlug)->create([
            'user_id' => $member->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);
    }

    return $member->fresh();
}

function bvVisitHandle(User $performer, User $member): string
{
    return FanAlias::handle($performer->performerProfile->id, $member->id);
}

// ─── Coleta do sentido novo: performer → membro ──────────────────────────────

it('performer visita membro do catálogo — registra a linha', function () {
    $performer = bvPerformer();
    $member = bvMember();

    $this->actingAs($performer)
        ->postJson(route('performer.members.visit'), ['member_handle' => bvVisitHandle($performer, $member)])
        ->assertStatus(204);

    $this->assertDatabaseHas('member_profile_visits', [
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
    ]);
});

it('dedup de 30min — reabrir o perfil não gera linha nova', function () {
    $performer = bvPerformer();
    $member = bvMember();
    $handle = bvVisitHandle($performer, $member);

    $this->actingAs($performer)->postJson(route('performer.members.visit'), ['member_handle' => $handle])->assertStatus(204);
    $this->actingAs($performer)->postJson(route('performer.members.visit'), ['member_handle' => $handle])->assertStatus(204);

    $this->assertDatabaseCount('member_profile_visits', 1);
});

it('passada a janela de dedup, uma nova visita é registrada', function () {
    $performer = bvPerformer();
    $member = bvMember();

    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    $this->travel(ProfileVisitService::DEDUPE_MINUTES + 1)->minutes();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    $this->assertDatabaseCount('member_profile_visits', 2);
});

it('não registra visita a membro fora do catálogo (oculto) — 404, sem linha', function () {
    $performer = bvPerformer();
    $member = bvMember(['visible_to_performers' => false]);

    $this->actingAs($performer)
        ->postJson(route('performer.members.visit'), ['member_handle' => bvVisitHandle($performer, $member)])
        ->assertStatus(404);

    $this->assertDatabaseCount('member_profile_visits', 0);
});

it('não registra visita a membro inativo (defesa de borda do service)', function () {
    $performer = bvPerformer();
    $member = bvMember();
    // status não é mass-assignable (privilégio) — grava direto.
    $member->forceFill(['status' => 'banned'])->save();

    // Direto no service — o membro banido nem apareceria no catálogo, então esta
    // é a rede redundante.
    $visit = app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member->fresh());

    expect($visit)->toBeNull();
    $this->assertDatabaseCount('member_profile_visits', 0);
});

// ─── Leitura do membro: "quem me visitou" ────────────────────────────────────

it('o membro vê as performers que o visitaram COM a identidade pública', function () {
    $performer = bvPerformer();
    $member = bvMember();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    $this->actingAs($member)->get(route('consumer.visitors.index'))->assertOk();

    $rows = app(ProfileVisitService::class)->memberVisitorsPanelFor($member);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['stage_name'])->toBe($performer->performerProfile->stage_name)
        ->and($rows->first()['slug'])->toBe($performer->performerProfile->slug)
        ->and($rows->first())->toHaveKey('avatar_url')
        ->and($rows->first())->toHaveKey('visited_slot');
});

it('a lista traz UMA linha por performer (a visita mais recente)', function () {
    $performer = bvPerformer();
    $member = bvMember();
    $service = app(ProfileVisitService::class);

    $service->recordPerformerVisit($performer->performerProfile, $member);
    $this->travel(ProfileVisitService::DEDUPE_MINUTES + 1)->minutes();
    $service->recordPerformerVisit($performer->performerProfile, $member);

    expect($service->memberVisitorsPanelFor($member))->toHaveCount(1);
});

it('visita de performer não verificada/suspensa não aparece para o membro', function () {
    $performer = bvPerformer();
    $member = bvMember();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    $performer->performerProfile->update(['is_verified' => false]);
    expect(app(ProfileVisitService::class)->memberVisitorsPanelFor($member))->toHaveCount(0);

    $performer->performerProfile->update(['is_verified' => true]);
    $performer->forceFill(['status' => 'banned'])->save();
    expect(app(ProfileVisitService::class)->memberVisitorsPanelFor($member))->toHaveCount(0);
});

it('a página do membro renderiza o componente e as performers', function () {
    $performer = bvPerformer();
    $member = bvMember();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    $this->actingAs($member)
        ->get(route('consumer.visitors.index'))
        ->assertInertia(fn (Assert $p) => $p
            ->component('Consumer/Visitors/Index')
            ->has('performers', 1)
            ->where('performers.0.stage_name', $performer->performerProfile->stage_name));
});

// ─── Privacidade: a performer NUNCA vê PII do membro ─────────────────────────

it('a resposta da visita não devolve nada do membro (204 vazio)', function () {
    $performer = bvPerformer();
    $member = bvMember(['name' => 'Nome Real', 'email' => 'real@example.com']);

    $res = $this->actingAs($performer)
        ->postJson(route('performer.members.visit'), ['member_handle' => bvVisitHandle($performer, $member)])
        ->assertStatus(204);

    expect($res->getContent())->toBe('')
        ->and($res->getContent())->not->toContain('Nome Real')
        ->and($res->getContent())->not->toContain('real@example.com');
});

// ─── O sentido ANTIGO (membro → performer) fica INTOCADO ─────────────────────

it('membro visitando performer continua registrando no sentido antigo', function () {
    $performer = bvPerformer();
    $member = bvMember();

    app(ProfileVisitService::class)->record($member, $performer->performerProfile);

    $this->assertDatabaseHas('profile_visits', [
        'visitor_id' => $member->id,
        'performer_profile_id' => $performer->performerProfile->id,
    ]);
    // E NADA no sentido novo.
    $this->assertDatabaseCount('member_profile_visits', 0);
});

it('record() antigo continua rejeitando visitante não-consumer (performer)', function () {
    $performer = bvPerformer();
    $other = bvPerformer();

    // Uma performer "abrindo" o perfil de outra não vira ProfileVisit — o guard
    // de não-consumer do record() antigo segue de pé.
    $visit = app(ProfileVisitService::class)->record($performer, $other->performerProfile);

    expect($visit)->toBeNull();
    $this->assertDatabaseCount('profile_visits', 0);
});

it('membro Black (Ghost Mode) visitando performer continua NÃO registrado', function () {
    $performer = bvPerformer();
    $ghost = bvMember(circleSlug: 'black');

    expect($ghost->hasGhostMode())->toBeTrue();

    $visit = app(ProfileVisitService::class)->record($ghost, $performer->performerProfile);

    expect($visit)->toBeNull();
    $this->assertDatabaseCount('profile_visits', 0);
});

it('membro Black PODE ser visitado por uma performer e vê a visita (Ghost Mode não se aplica ao inverso)', function () {
    // Ghost Mode protege o MEMBRO de ser LISTADO para a performer (sentido antigo).
    // No sentido novo quem é exposto é a performer (pública) — o perk não se aplica,
    // e o membro Black vê normalmente quem o visitou.
    $performer = bvPerformer();
    $black = bvMember(circleSlug: 'black');

    $visit = app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $black);

    expect($visit)->not->toBeNull();
    expect(app(ProfileVisitService::class)->memberVisitorsPanelFor($black))->toHaveCount(1);
});

// ─── Gate de rota ────────────────────────────────────────────────────────────

it('membro não pode postar visita (rota é role:performer)', function () {
    $member = bvMember();
    $target = bvMember();

    $this->actingAs($member)
        ->postJson(route('performer.members.visit'), ['member_handle' => 'deadbeefdeadbeef'])
        ->assertStatus(403);
});

it('visitante deslogado não acessa a tela do membro', function () {
    $this->get(route('consumer.visitors.index'))->assertRedirect();
});

// ─── GC / retenção (7 dias, nos dois sentidos) ───────────────────────────────

it('purgeExpired varre member_profile_visits fora dos 7 dias e preserva as recentes', function () {
    $performer = bvPerformer();
    $recentMember = bvMember();
    $oldMember = bvMember();
    $service = app(ProfileVisitService::class);

    $service->recordPerformerVisit($performer->performerProfile, $recentMember);

    // Visita antiga: força a data para além da retenção.
    $old = $service->recordPerformerVisit($performer->performerProfile, $oldMember);
    $old->forceFill(['visited_at' => now()->subDays(ProfileVisitService::RETENTION_DAYS + 1)])->save();

    $service->purgeExpired();

    $this->assertDatabaseCount('member_profile_visits', 1);
    $this->assertDatabaseHas('member_profile_visits', ['member_id' => $recentMember->id]);
});

// ─── Hard Delete nos dois sentidos ───────────────────────────────────────────

it('Hard Delete do MEMBRO apaga as visitas recebidas por ele', function () {
    $performer = bvPerformer();
    $member = bvMember();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    app(DeletionService::class)->executeDeletion($member->fresh());

    $this->assertDatabaseCount('member_profile_visits', 0);
});

it('Hard Delete da PERFORMER apaga as visitas que ela fez a membros', function () {
    $performer = bvPerformer();
    $member = bvMember();
    app(ProfileVisitService::class)->recordPerformerVisit($performer->performerProfile, $member);

    app(DeletionService::class)->executeDeletion($performer->fresh());

    $this->assertDatabaseCount('member_profile_visits', 0);
});
