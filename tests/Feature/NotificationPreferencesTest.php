<?php

use App\Models\User;

/**
 * Sons de notificação (Sprint 16): preferência por-usuário (message/tip/live),
 * todas ON por padrão, um toggle por vez via endpoint role-neutro.
 */

// ─── Accessor / default ──────────────────────────────────────────────────────

it('trata usuário sem preferência como todos os sons ligados', function () {
    $user = User::factory()->create(['notification_preferences' => null]);

    expect($user->notificationSoundPreferences())->toBe([
        'message' => true,
        'tip' => true,
        'live' => true,
    ]);
});

it('só silencia a chave com false explícito, mantendo as demais ON', function () {
    $user = User::factory()->create(['notification_preferences' => ['tip' => false]]);

    expect($user->notificationSoundPreferences())->toBe([
        'message' => true,
        'tip' => false,
        'live' => true,
    ]);
});

it('ignora chaves desconhecidas no JSON ao ler', function () {
    $user = User::factory()->create([
        'notification_preferences' => ['message' => false, 'lixo' => 'x'],
    ]);

    expect($user->notificationSoundPreferences())->toBe([
        'message' => false,
        'tip' => true,
        'live' => true,
    ]);
});

// ─── Endpoint ────────────────────────────────────────────────────────────────

it('desliga um som e persiste, preservando as outras chaves', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->patch(route('notifications.sound.update'), ['key' => 'message', 'enabled' => false])
        ->assertRedirect();

    expect($user->fresh()->notificationSoundPreferences())->toBe([
        'message' => false,
        'tip' => true,
        'live' => true,
    ]);
});

it('religa um som previamente desligado', function () {
    $user = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'notification_preferences' => ['message' => false],
    ]);

    $this->actingAs($user)
        ->patch(route('notifications.sound.update'), ['key' => 'message', 'enabled' => true])
        ->assertRedirect();

    expect($user->fresh()->notificationSoundPreferences()['message'])->toBeTrue();
});

it('é role-neutro: a performer também ajusta o próprio som', function () {
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    $this->actingAs($performer)
        ->patch(route('notifications.sound.update'), ['key' => 'tip', 'enabled' => false])
        ->assertRedirect();

    expect($performer->fresh()->notificationSoundPreferences()['tip'])->toBeFalse();
});

it('mantém o JSON limpo: escrita nunca introduz chave fora da allowlist', function () {
    $user = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'notification_preferences' => ['lixo' => 'x', 'live' => false],
    ]);

    $this->actingAs($user)
        ->patch(route('notifications.sound.update'), ['key' => 'message', 'enabled' => false]);

    // Arr::only descarta 'lixo'; 'live' preservado; 'message' escrito.
    expect($user->fresh()->notification_preferences)
        ->toBe(['live' => false, 'message' => false]);
});

// Rota WEB (consumida pelo Inertia router.patch): falha de validação volta como
// redirect-com-erros-de-sessão, não 422 JSON — shouldRenderJsonWhen só liga o
// JSON em api/* (ver CLAUDE.md). É o mesmo padrão do togglePrivacyPerk.
it('rejeita chave fora da allowlist', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->from('/configuracoes')
        ->patch(route('notifications.sound.update'), ['key' => 'email', 'enabled' => false])
        ->assertSessionHasErrors('key');
});

it('exige enabled booleano', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->from('/configuracoes')
        ->patch(route('notifications.sound.update'), ['key' => 'message'])
        ->assertSessionHasErrors('enabled');
});

it('barra usuário não autenticado', function () {
    $this->patch(route('notifications.sound.update'), ['key' => 'message', 'enabled' => false])
        ->assertRedirect(route('login'));
});

// ─── Mass assignment ─────────────────────────────────────────────────────────

it('não permite mass assignment de notification_preferences', function () {
    $user = new User();
    $user->fill(['notification_preferences' => ['message' => false]]);

    expect($user->notification_preferences)->toBeNull();
});
