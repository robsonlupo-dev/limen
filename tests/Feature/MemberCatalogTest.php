<?php

use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InterestService;
use App\Support\FanAlias;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Catálogo de MEMBROS para a performer (Sprint 16). A performer navega membros
 * que optaram por aparecer e sinaliza interesse (Interesse Controlado invertido).
 *
 * O foco dos testes é a PRIVACIDADE (LOCKED): quem aparece, o padrão-por-tier do
 * Black/FC, e a ausência de qualquer PII do membro no payload da performer.
 */

// ─── Helpers ─────────────────────────────────────────────────────────────────

function catalogPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    return $user->fresh();
}

function catalogMember(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'email_verified_at' => now(),
    ], $attrs));
}

function subscribeTo(User $member, string $slug): void
{
    Subscription::factory()->circle($slug)->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);
}

// ─── Effective visibility (model) ────────────────────────────────────────────

it('membro comum sem escolha é visível por padrão', function () {
    expect(catalogMember()->isVisibleToPerformers())->toBeTrue();
});

it('membro Black sem escolha é oculto por padrão', function () {
    $member = catalogMember();
    subscribeTo($member, 'black');

    expect($member->fresh()->isVisibleToPerformers())->toBeFalse();
});

it('escolha explícita vence o padrão-por-tier', function () {
    $member = catalogMember(['visible_to_performers' => true]);
    subscribeTo($member, 'founders_circle');

    expect($member->fresh()->isVisibleToPerformers())->toBeTrue();
});

// ─── Quem aparece no catálogo ────────────────────────────────────────────────

it('lista membro comum', function () {
    $performer = catalogPerformer();
    catalogMember();

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Performer/Members')->has('members.data', 1));
});

it('esconde Black/FC por padrão (sem escolha)', function () {
    $performer = catalogPerformer();
    subscribeTo(catalogMember(), 'black');

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
});

it('mostra Black/FC que optou por aparecer', function () {
    $performer = catalogPerformer();
    subscribeTo(catalogMember(['visible_to_performers' => true]), 'black');

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->has('members.data', 1));
});

it('esconde membro que desligou a visibilidade', function () {
    $performer = catalogPerformer();
    catalogMember(['visible_to_performers' => false]);

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
});

it('esconde membro em Modo Discreto', function () {
    $performer = catalogPerformer();
    catalogMember(['discrete_mode' => true]);

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
});

it('esconde membro não verificado', function () {
    $performer = catalogPerformer();
    catalogMember(['email_verified_at' => null]);

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
});

// ─── Privacidade do payload ──────────────────────────────────────────────────

it('não expõe PII do membro no payload', function () {
    $performer = catalogPerformer();
    catalogMember([
        'name' => 'Zebedeu Segredo',
        'email' => 'zeb-oculto@example.test',
    ]);

    $response = $this->actingAs($performer)->get(route('performer.members'));

    // Nenhum dado real do membro na resposta (o data-page do Inertia é JSON no HTML).
    $response->assertDontSee('Zebedeu Segredo');
    $response->assertDontSee('zeb-oculto@example.test');

    // O item traz SÓ o conjunto mascarado — nada de name/email/tier/circle/saldo/id.
    $response->assertInertia(fn (Assert $p) => $p
        ->has('members.data.0', fn (Assert $item) => $item
            ->hasAll(['fan_alias_label', 'member_handle', 'avatar_url', 'activity_label', 'interest_sent'])
            ->missing('name')
            ->missing('email')
            ->missing('tier')
            ->missing('circle')
            ->missing('balance')
            ->missing('id')
            ->etc()
        )
    );
});

it('o rótulo do membro é o FanAlias, não o id', function () {
    $performer = catalogPerformer();
    $member = catalogMember();
    $profileId = $performer->performerProfile->id;

    $this->actingAs($performer)
        ->get(route('performer.members'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('members.data.0.fan_alias_label', FanAlias::label($profileId, $member->id, 'Membro #'))
            ->where('members.data.0.member_handle', FanAlias::handle($profileId, $member->id))
        );
});

// ─── Envio de interesse pelo catálogo ────────────────────────────────────────

it('performer verificada envia interesse pelo catálogo', function () {
    $performer = catalogPerformer();
    $member = catalogMember();
    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $this->actingAs($performer)
        ->postJson(route('performer.members.interest'), ['member_handle' => $handle])
        ->assertStatus(201)
        ->assertJson(['sent' => true]);

    $this->assertDatabaseHas('performer_interests', [
        'performer_profile_id' => $performer->performerProfile->id,
        'member_id' => $member->id,
        'source' => InterestService::SOURCE_CATALOG,
        'status' => 'sent',
    ]);
});

it('404 ao enviar para membro fora do catálogo', function () {
    $performer = catalogPerformer();
    // Membro oculto (desligou a visibilidade): não está no conjunto resolvível.
    $member = catalogMember(['visible_to_performers' => false]);
    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $this->actingAs($performer)
        ->postJson(route('performer.members.interest'), ['member_handle' => $handle])
        ->assertStatus(404);

    $this->assertDatabaseCount('performer_interests', 0);
});

it('o cooldown por par é compartilhado com as outras origens', function () {
    $performer = catalogPerformer();
    $member = catalogMember();
    $handle = FanAlias::handle($performer->performerProfile->id, $member->id);

    $this->actingAs($performer)
        ->postJson(route('performer.members.interest'), ['member_handle' => $handle])
        ->assertStatus(201);

    // Reenvio imediato ao MESMO membro (mesmo par) → cooldown, 422.
    $this->actingAs($performer)
        ->postJson(route('performer.members.interest'), ['member_handle' => $handle])
        ->assertStatus(422);

    $this->assertDatabaseCount('performer_interests', 1);
});

// ─── Toggle do membro ────────────────────────────────────────────────────────

it('membro grava a escolha explícita de visibilidade', function () {
    $member = catalogMember();

    $this->actingAs($member)
        ->patch(route('consumer.settings.performer-visibility'), ['enabled' => false])
        ->assertRedirect();

    expect($member->fresh()->visible_to_performers)->toBeFalse();

    $this->actingAs($member)
        ->patch(route('consumer.settings.performer-visibility'), ['enabled' => true])
        ->assertRedirect();

    expect($member->fresh()->visible_to_performers)->toBeTrue();
});

it('visible_to_performers não é mass-assignable', function () {
    $user = new User();
    $user->fill(['visible_to_performers' => false]);

    expect($user->visible_to_performers)->toBeNull();
});
