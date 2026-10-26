<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Tela de pós-cadastro (verification.notice) role-aware + flag de redirecionamento
 * do pré-lançamento (feat/signup-email-gate-and-whatsapp-optin).
 */
beforeEach(function () {
    config()->set('referral.enabled', true);
    config()->set('signup.prelaunch_welcome', true);
});

it('a notice entrega o prop welcome com papel de membro e código de indicação', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('welcome.role', 'member')
            ->where('welcome.referral_enabled', true)
            ->whereNot('welcome.referral_code', null)
        );
});

it('a notice marca o papel criadora para performer', function () {
    $user = User::factory()->performer()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('welcome.role', 'performer'));
});

it('conta indicações feitas e quantas confirmaram o e-mail', function () {
    $referrer = User::factory()->unverified()->create();
    $svc = app(\App\Services\ReferralService::class);
    $svc->ensureCode($referrer);

    // Dois indicados: um confirmou o e-mail, outro não.
    $confirmed = User::factory()->create();            // email_verified_at = now (default)
    $pending = User::factory()->unverified()->create();
    $svc->attributeAtSignup($confirmed, $referrer->referral_code, 'code');
    $svc->attributeAtSignup($pending, $referrer->referral_code, 'code');

    $this->actingAs($referrer)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('welcome.counters.sent', 2)
            ->where('welcome.counters.confirmed', 1)
        );
});

it('no pré-lançamento (flag on) o cadastro de membro cai na tela de boas-vindas', function () {
    config(['signup.prelaunch_welcome' => true]);

    $this->post('/cadastro', [
        'tipo' => 'membro',
        'name' => 'Membro Welcome',
        'email' => 'membro.welcome@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-06-15',
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('verification.notice'));
});

it('no pré-lançamento (flag on) o cadastro de criadora cai na tela de boas-vindas', function () {
    config(['signup.prelaunch_welcome' => true]);

    $this->post('/cadastro', [
        'tipo' => 'performer',
        'name' => 'Criadora Welcome',
        'email' => 'criadora.welcome@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1995-03-10',
        'stage_name' => 'Estrela Welcome',
        'worlds' => ['mulheres'],
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('verification.notice'));
});
