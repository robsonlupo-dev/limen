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
