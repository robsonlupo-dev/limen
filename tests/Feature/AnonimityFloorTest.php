<?php

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FollowService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// ─── Helpers ────────────────────────────────────────────────────────────────
// Nomes próprios: os helpers de InterestSystemTest são funções globais.

function floorPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf ' . Str::random(4),
        'slug' => 'perf-' . strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function floorMember(bool $discrete = false): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($discrete) {
        $member->discrete_mode = true;
        $member->save();
    }

    return $member;
}

/** @return array<int, User> */
function followersFor(PerformerProfile $profile, int $count, bool $discrete = false): array
{
    return collect(range(1, $count))->map(function () use ($profile, $discrete) {
        $member = floorMember($discrete);
        Follow::create([
            'user_id' => $member->id,
            'performer_profile_id' => $profile->id,
            'discrete_mode' => $discrete,
        ]);

        return $member;
    })->all();
}

/** Membro com Círculo ativo do tier pedido. */
function memberOfCircle(string $slug): User
{
    $member = floorMember();
    Subscription::factory()->circle($slug)->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    return $member;
}

function followersPage(PerformerProfile $profile)
{
    return test()->actingAs($profile->user)->get(route('performer.followers'));
}

// ─── Piso de Anonimato ───────────────────────────────────────────────────────

it('abaixo do piso a performer nao ve lista nenhuma, e sabe por que', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);

    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Performer/Followers')
        ->where('below_floor', true)
        ->where('total_followers', 4)
        ->where('followers.data', [])
        ->where('floor_message', 'Para proteger o anonimato dos membros Limen, a lista de seguidores fica visível a partir de 5 seguidores.')
    );
});

it('a partir do piso a lista aparece, anonimizada', function () {
    $profile = floorPerformer();
    $members = followersFor($profile, 5);

    $response = followersPage($profile);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers', 5)
        ->has('followers.data', 5)
        ->where('floor_message', null)
    );

    // Continua sem PII: só "Membro #id".
    $response->assertSee('Membro #' . $members[0]->id, false);
    expect($response->getContent())->not->toContain($members[0]->email);
});

it('nenhum id vaza no payload quando esta abaixo do piso', function () {
    $profile = floorPerformer();
    $members = followersFor($profile, 3);

    $response = followersPage($profile);

    // O ponto do piso: o id não pode estar em lugar nenhum da resposta, nem
    // escondido num campo que a UI não usa.
    foreach ($members as $member) {
        expect($response->getContent())->not->toContain('Membro #' . $member->id);
    }
});

// ─── Modo Discreto na lista ──────────────────────────────────────────────────

it('membro discreto conta para o total mas nao aparece na lista', function () {
    $profile = floorPerformer();
    $visible = followersFor($profile, 5);
    $hidden = followersFor($profile, 2, discrete: true);

    $response = followersPage($profile);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers', 7)   // discretos contam
        ->has('followers.data', 5)      // mas não são listados
    );

    foreach ($hidden as $member) {
        expect($response->getContent())->not->toContain('Membro #' . $member->id);
    }
    expect($response->getContent())->toContain('Membro #' . $visible[0]->id);
});

it('membro discreto ajuda a atingir o piso', function () {
    $profile = floorPerformer();
    $visible = followersFor($profile, 4);
    followersFor($profile, 1, discrete: true);

    // 4 visíveis + 1 discreto = 5: o piso é atingido e os 4 aparecem.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', false)
        ->where('total_followers', 5)
        ->has('followers.data', 4)
    );
});

it('sem o discreto o piso nao seria atingido e nada apareceria', function () {
    $profile = floorPerformer();
    followersFor($profile, 4);

    // Mesmo cenário do teste anterior, menos o discreto: continua abaixo.
    followersPage($profile)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('below_floor', true)
        ->where('followers.data', [])
    );
});

it('divergencia entre a flag do follow e a do usuario esconde o membro', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    // Drift: o usuário está discreto mas a linha do follow ficou para trás
    // (ex.: update parcial). Na dúvida, esconde.
    $drifted = floorMember(discrete: true);
    Follow::create([
        'user_id' => $drifted->id,
        'performer_profile_id' => $profile->id,
        'discrete_mode' => false,
    ]);

    $response = followersPage($profile);

    expect($response->getContent())->not->toContain('Membro #' . $drifted->id);
});

// ─── Toggle do Modo Discreto ─────────────────────────────────────────────────

it('membro Black ativa o Modo Discreto', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk()
        ->assertJson(['discrete_mode' => true]);

    expect($member->fresh()->discrete_mode)->toBeTrue();
});

it('membro Founders Circle ativa o Modo Discreto', function () {
    $member = memberOfCircle('founders_circle');

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk()
        ->assertJson(['discrete_mode' => true]);
});

it('tiers abaixo de Black recebem 403', function (string $slug) {
    $member = memberOfCircle($slug);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden()
        ->assertJson(['message' => 'Modo Discreto disponível apenas para membros Black e Founders Circle']);

    expect($member->fresh()->discrete_mode)->toBeFalse();
})->with(['explorador', 'insider', 'prestige']);

it('membro sem assinatura recebe 403', function () {
    $member = floorMember();

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden();

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

it('assinatura Black expirada nao vale como elegivel', function () {
    $member = floorMember();
    Subscription::factory()->circle('black')->create([
        'user_id' => $member->id,
        'status' => 'active',
        'current_period_end' => now()->subDay(), // período vencido
    ]);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertForbidden();
});

it('o toggle desliga quando ja estava ligado', function () {
    $member = memberOfCircle('black');

    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertJson(['discrete_mode' => true]);

    $this->actingAs($member->fresh(), 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertJson(['discrete_mode' => false]);

    expect($member->fresh()->discrete_mode)->toBeFalse();
});

// ─── Propagação ──────────────────────────────────────────────────────────────

it('ativar o modo atualiza todos os follows existentes', function () {
    $member = memberOfCircle('black');
    $profiles = collect(range(1, 3))->map(fn () => floorPerformer());

    foreach ($profiles as $profile) {
        Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);
    }

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    expect(Follow::where('user_id', $member->id)->where('discrete_mode', true)->count())->toBe(3);

    // E desligar reverte todos.
    $this->actingAs($member->fresh(), 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    expect(Follow::where('user_id', $member->id)->where('discrete_mode', false)->count())->toBe(3);
});

it('novo follow ja nasce discreto quando o membro esta em Modo Discreto', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))->assertOk();

    $profile = floorPerformer();

    // Caminho web (FollowService) — é o que o frontend Vue usa de fato.
    app(FollowService::class)->follow($member->fresh(), $profile);

    $follow = Follow::where('user_id', $member->id)->where('performer_profile_id', $profile->id)->first();
    expect($follow->discrete_mode)->toBeTrue();
});

it('novo follow pela API tambem nasce discreto', function () {
    $member = memberOfCircle('black');
    $this->actingAs($member, 'sanctum')->patchJson(route('consumer.preferences.discrete-mode'))->assertOk();

    $profile = floorPerformer();

    $this->actingAs($member->fresh(), 'sanctum')
        ->postJson("/api/v1/performers/{$profile->slug}/follow")
        ->assertSuccessful();

    expect(Follow::where('user_id', $member->id)->where('performer_profile_id', $profile->id)
        ->value('discrete_mode'))->toBeTrue();
});

it('follow de membro normal nasce visivel', function () {
    $member = floorMember();
    $profile = floorPerformer();

    app(FollowService::class)->follow($member, $profile);

    expect(Follow::where('user_id', $member->id)->value('discrete_mode'))->toBeFalse();
});

// ─── Fim a fim ───────────────────────────────────────────────────────────────

it('membro que ativa o modo some da lista de quem ele ja seguia', function () {
    $profile = floorPerformer();
    followersFor($profile, 5);

    $member = memberOfCircle('black');
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $profile->id]);

    // Antes: aparece (6 seguidores, acima do piso).
    expect(followersPage($profile)->getContent())->toContain('Membro #' . $member->id);

    $this->actingAs($member, 'sanctum')
        ->patchJson(route('consumer.preferences.discrete-mode'))
        ->assertOk();

    // Depois: sumiu da lista, mas continua contando para o piso.
    $response = followersPage($profile);
    expect($response->getContent())->not->toContain('Membro #' . $member->id);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('total_followers', 6)
        ->has('followers.data', 5)
    );
});
