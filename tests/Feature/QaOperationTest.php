<?php

use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Gaps identificados pela Operação de QA (02/07/2026) sobre a suíte existente.

function qaUser(array $overrides = []): User
{
    return User::factory()->create($overrides);
}

function qaPerformer(array $profileOverrides = []): array
{
    $user = User::factory()->performer()->create(['status' => 'active']);
    $profile = PerformerProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'is_verified' => true,
    ], $profileOverrides));

    return [$user, $profile];
}

// --- Wave A: login de conta suspensa/banida ---

it('blocks web login for suspended users', function () {
    qaUser(['email' => 'sus@qa.test', 'password' => Hash::make('Password1'), 'status' => 'suspended']);

    $this->post('/login', ['email' => 'sus@qa.test', 'password' => 'Password1'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('blocks web login for banned users', function () {
    qaUser(['email' => 'ban@qa.test', 'password' => Hash::make('Password1'), 'status' => 'banned']);

    $this->post('/login', ['email' => 'ban@qa.test', 'password' => 'Password1'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('blocks api login for suspended users', function () {
    qaUser(['email' => 'sus-api@qa.test', 'password' => Hash::make('Password1'), 'status' => 'suspended']);

    $this->postJson('/api/v1/auth/login', ['email' => 'sus-api@qa.test', 'password' => 'Password1'])
        ->assertStatus(401);
});

// --- security-validator: preferred_world é validado contra o enum de mundos ---
// (setá-lo no cadastro é feature intencional — via atribuição explícita, não fillable)

it('rejects an invalid preferred_world on web registration', function () {
    $this->post('/cadastro', [
        'tipo' => 'membro',
        'name' => 'Injetor',
        'email' => 'inject@qa.test',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(25)->format('Y-m-d'),
        'accept_terms' => true,
        'lgpd_consent' => true,
        'preferred_world' => 'nao-existe',
    ])->assertSessionHasErrors('preferred_world');

    expect(User::where('email', 'inject@qa.test')->exists())->toBeFalse();
});

// --- bug-hunter: limites e unicode ---

it('rejects a tip with an absurdly large amount without touching the ledger', function () {
    $consumer = qaUser();
    app(TokenService::class)->credit($consumer, 500, 'bonus');
    [, $profile] = qaPerformer();

    $this->actingAs($consumer)
        ->postJson('/api/v1/tips', [
            'performer_slug' => $profile->slug,
            'amount' => 2 ** 40,
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    expect(Tip::count())->toBe(0)
        ->and(app(TokenService::class)->balance($consumer))->toBe(500);
});

it('stores unicode and emoji in tip messages without breaking', function () {
    $consumer = qaUser();
    app(TokenService::class)->credit($consumer, 500, 'bonus');
    [, $profile] = qaPerformer();

    $message = 'Você é incrível 🔥💛 — até já!';

    $this->actingAs($consumer)
        ->postJson('/api/v1/tips', [
            'performer_slug' => $profile->slug,
            'amount' => 10,
            'idempotency_key' => (string) Str::uuid(),
            'message' => $message,
        ])
        ->assertCreated();

    expect(Tip::first()->message)->toBe($message);
});

// --- bug-hunter: paginação abusiva não pode dar 500 ---

it('handles hostile pagination values on the catalog without a server error', function () {
    $consumer = qaUser();

    foreach (['page=-1', 'page=0', 'page=999999', 'page=abc'] as $query) {
        $this->actingAs($consumer)
            ->get("/catalogo?{$query}")
            ->assertSuccessful();
    }
});

// --- bug-hunter: performer soft-deleted some do catálogo e do perfil ---

it('hides a soft-deleted performer from catalog and profile page', function () {
    $consumer = qaUser();
    [, $profile] = qaPerformer();
    $slug = $profile->slug;

    $this->actingAs($consumer)->get("/catalogo/{$slug}")->assertSuccessful();

    $profile->delete();

    $this->actingAs($consumer)->get("/catalogo/{$slug}")->assertNotFound();
});
