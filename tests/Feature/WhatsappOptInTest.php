<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Opt-in de WhatsApp da tela de pós-cadastro (feat/signup-email-gate-and-whatsapp-optin).
 * O Chatwoot nasce desligado (config), então o push é no-op e nenhum teste toca a rede.
 */
beforeEach(function () {
    config()->set('chatwoot.enabled', false);
});

it('grava o número E.164 e o carimbo de consentimento no opt-in', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson(route('welcome.whatsapp.store'), [
            'whatsapp' => '(11) 91234-5678',
            'consent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $user->refresh();
    expect($user->whatsapp)->toBe('+5511912345678')
        ->and($user->whatsapp_consent_at)->not->toBeNull();
});

it('recusa o opt-in sem consentimento (caixa desmarcada)', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson(route('welcome.whatsapp.store'), [
            'whatsapp' => '(11) 91234-5678',
            'consent' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent');

    expect($user->fresh()->whatsapp)->toBeNull();
});

it('recusa número de celular inválido', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson(route('welcome.whatsapp.store'), [
            'whatsapp' => '123',
            'consent' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('whatsapp');

    expect($user->fresh()->whatsapp)->toBeNull();
});

it('revoga o opt-in no destroy', function () {
    $user = User::factory()->unverified()->create();
    $user->forceFill(['whatsapp' => '+5511912345678', 'whatsapp_consent_at' => now()])->save();

    $this->actingAs($user)
        ->deleteJson(route('welcome.whatsapp.destroy'))
        ->assertOk();

    $user->refresh();
    expect($user->whatsapp)->toBeNull()
        ->and($user->whatsapp_consent_at)->toBeNull();
});

it('exige autenticação', function () {
    // Rota WEB: o visitante não-logado é mandado ao login (302), não 401 — é o
    // contrato do app (o lib/http trata esse redirect como falha). O que importa:
    // guest não faz opt-in.
    $this->postJson(route('welcome.whatsapp.store'), [
        'whatsapp' => '(11) 91234-5678',
        'consent' => true,
    ])->assertRedirect(route('login'));
});
