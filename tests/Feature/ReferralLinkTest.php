<?php

use App\Models\PerformerProfile;
use App\Models\ReferralLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Links personalizados de indicação (feat/referral-custom-links): criação (teto,
 * formato, reservadas, anti-personificação, unicidade — recusas genéricas
 * anti-oráculo) e a porta pública /r/{slug} (cookie do ?ref= + destino por fase).
 */
beforeEach(function () {
    config()->set('referral.enabled', true);
    config()->set('referral.custom_links_max', 2);
});

const RL_GENERIC = 'Desculpe, este link já existe. Por favor, tente usar outro.';

function rlUser(): User
{
    return User::factory()->create()->refresh();
}

it('cria o link normalizando para minúsculas e mostra na tela', function () {
    $user = rlUser();

    $this->actingAs($user)
        ->postJson(route('referral.links.store'), ['slug' => 'Robson'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('link.slug', 'robson');

    expect(ReferralLink::where('user_id', $user->id)->pluck('slug')->all())->toBe(['robson']);

    $this->actingAs($user)->get(route('referral.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Referral/Index')
            ->where('custom_links.0.slug', 'robson')
            ->where('custom_links_max', 2)
        );
});

it('recusa formato inválido com a mensagem explícita', function () {
    $user = rlUser();

    foreach (['ab', '-abc', 'abc-', 'ro bson', 'Rõbson!', str_repeat('a', 21)] as $bad) {
        $this->actingAs($user)
            ->postJson(route('referral.links.store'), ['slug' => $bad])
            ->assertStatus(422);
    }

    expect(ReferralLink::count())->toBe(0);
});

it('aplica o teto de links por usuário', function () {
    $user = rlUser();

    $this->actingAs($user)->postJson(route('referral.links.store'), ['slug' => 'meu-link-um'])->assertOk();
    $this->actingAs($user)->postJson(route('referral.links.store'), ['slug' => 'meu-link-dois'])->assertOk();

    $this->actingAs($user)
        ->postJson(route('referral.links.store'), ['slug' => 'meu-link-tres'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Você já criou seus 2 links personalizados.');
});

it('recusa palavra reservada com a resposta genérica (anti-oráculo)', function () {
    $user = rlUser();

    foreach (['suporte-vip', 'limen-promo', 'cadastro123'] as $reserved) {
        $this->actingAs($user)
            ->postJson(route('referral.links.store'), ['slug' => $reserved])
            ->assertStatus(422)
            ->assertJsonPath('message', RL_GENERIC);
    }
});

it('recusa nome artístico de criadora (anti-personificação), com leet', function () {
    $performer = User::factory()->performer()->create();
    PerformerProfile::factory()->for($performer)->create(['stage_name' => 'Luna Prado']);

    $user = rlUser();

    foreach (['luna-prado', 'lunaprado', 'lun4-prado'] as $slug) {
        $this->actingAs($user)
            ->postJson(route('referral.links.store'), ['slug' => $slug])
            ->assertStatus(422)
            ->assertJsonPath('message', RL_GENERIC);
    }
});

it('recusa telefone disfarçado com a resposta genérica', function () {
    $this->actingAs(rlUser())
        ->postJson(route('referral.links.store'), ['slug' => 'zap21995429525'])
        ->assertStatus(422)
        ->assertJsonPath('message', RL_GENERIC);
});

it('recusa slug já tomado por outro usuário, com a mesma resposta genérica', function () {
    $dona = rlUser();
    $this->actingAs($dona)->postJson(route('referral.links.store'), ['slug' => 'portal-da-ana'])->assertOk();

    $this->actingAs(rlUser())
        ->postJson(route('referral.links.store'), ['slug' => 'Portal-Da-Ana'])
        ->assertStatus(422)
        ->assertJsonPath('message', RL_GENERIC);
});

it('exige login para criar (guest vai para o login)', function () {
    $this->postJson(route('referral.links.store'), ['slug' => 'alguem'])
        ->assertRedirect(route('login'));
});

it('/r/{slug} grava o cookie do ref e, no pré-lançamento, manda para a home', function () {
    config()->set('features.landing_prelaunch', true);

    $dona = rlUser();
    app(\App\Services\ReferralLinkService::class)->create($dona, 'minha-porta');
    $code = app(\App\Services\ReferralService::class)->ensureCode($dona);

    $this->get('/r/minha-porta')
        ->assertRedirect(route('landing'))
        ->assertCookie('referral_ref', $code);
});

it('/r/{slug} fora do pré-lançamento manda direto para o cadastro com o ref', function () {
    config()->set('features.landing_prelaunch', false);

    $dona = rlUser();
    app(\App\Services\ReferralLinkService::class)->create($dona, 'minha-porta');
    $code = app(\App\Services\ReferralService::class)->ensureCode($dona);

    $this->get('/r/minha-porta')
        ->assertRedirect(route('register', ['ref' => $code]))
        ->assertCookie('referral_ref', $code);
});

it('/r/{slug} desconhecido volta para a home sem cookie (mesma resposta externa)', function () {
    $this->get('/r/nao-existe')
        ->assertRedirect(route('landing'))
        ->assertCookieMissing('referral_ref');
});

it('o cadastro via /r/{slug} atribui a indicação ao dono do link', function () {
    config()->set('features.landing_prelaunch', false);
    config()->set('signup.prelaunch_welcome', false);

    $dona = rlUser();
    app(\App\Services\ReferralLinkService::class)->create($dona, 'porta-real');

    // Visita o link como visitante (grava o cookie) — o test client não carrega
    // cookies entre requests, então o cadastro abaixo envia o cookie explícito
    // (cifrado, como o EncryptCookies espera).
    $this->get('/r/porta-real')->assertRedirect(route('register', ['ref' => $dona->fresh()->referral_code]));

    $this->withCookie('referral_ref', $dona->fresh()->referral_code)
        ->post('/cadastro', [
            'tipo' => 'membro',
            'name' => 'Indicado Pelo Link',
            'email' => 'indicado.link@gmail.com',
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
            'birthdate' => '1990-06-15',
            'cpf' => '529.982.247-25',
            'accept_terms' => true,
            'lgpd_consent' => true,
        ]);

    $novo = User::where('email', 'indicado.link@gmail.com')->firstOrFail();
    expect($novo->referred_by_user_id)->toBe($dona->id);
});
