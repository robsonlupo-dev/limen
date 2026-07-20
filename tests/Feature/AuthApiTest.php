<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

function consumerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Consumer',
        'email' => 'consumer@example.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'birthdate' => now()->subYears(20)->format('Y-m-d'),
        'phone' => '11999999999',
        // Obrigatório desde a verificação de maioridade (Sprint 6). CPF de teste
        // estruturalmente válido — não pertence a ninguém.
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
        'terms_version' => '1.0',
    ], $overrides);
}

function performerPayload(array $overrides = []): array
{
    return array_merge(consumerPayload([
        'email' => 'performer@example.com',
    ]), [
        'stage_name' => 'StageName',
        'category' => 'mulheres',
        'cpf' => '529.982.247-25',
    ], $overrides);
}

// 1. Consumer registration ok -> 201, user+wallet created, token works
it('registers a consumer with wallet and returns a working token', function () {
    $response = $this->postJson('/api/v1/auth/register/consumer', consumerPayload());

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role'], 'token']);

    $this->assertDatabaseHas('users', [
        'email' => 'consumer@example.com',
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('token_wallets', [
        'user_id' => $response->json('data.id'),
        'balance' => 0,
    ]);

    $token = $response->json('token');
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])
        ->assertOk();
});

// 2. Under 18 -> 422
it('rejects consumer registration for users under 18', function () {
    $response = $this->postJson('/api/v1/auth/register/consumer', consumerPayload([
        'birthdate' => now()->subYears(17)->format('Y-m-d'),
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('birthdate');
});

// 3. No accept_terms -> 422
it('rejects consumer registration without accepting terms', function () {
    $response = $this->postJson('/api/v1/auth/register/consumer', consumerPayload([
        'accept_terms' => false,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('accept_terms');
});

// 4. Duplicate email -> 422
it('rejects registration with duplicate email', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $response = $this->postJson('/api/v1/auth/register/consumer', consumerPayload([
        'email' => 'dup@example.com',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

// 5. Weak password -> 422
it('rejects registration with weak password', function () {
    $response = $this->postJson('/api/v1/auth/register/consumer', consumerPayload([
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

// 6. Performer registration -> user pending + profile + wallet
it('registers a performer with pending status, profile, and wallet', function () {
    $response = $this->postJson('/api/v1/auth/register/performer', performerPayload());

    $response->assertStatus(201)
        ->assertJsonPath('data.role', 'performer')
        ->assertJsonPath('data.status', 'pending');

    $userId = $response->json('data.id');

    $this->assertDatabaseHas('performer_profiles', [
        'user_id' => $userId,
        'stage_name' => 'StageName',
    ]);

    $this->assertDatabaseHas('token_wallets', [
        'user_id' => $userId,
        'balance' => 0,
    ]);

    $this->assertDatabaseHas('identity_verifications', [
        'user_id' => $userId,
        'status' => 'pending',
    ]);
});

// 7. Login ok -> 200 + token, last_login_at filled
it('logs in successfully and updates last_login_at', function () {
    $user = User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('Password1'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@example.com',
        'password' => 'Password1',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id'], 'token']);

    $user->refresh();
    expect($user->last_login_at)->not->toBeNull();
});

// 8. Wrong password -> 401; repeated beyond limit -> 429
it('returns 401 for wrong password and 429 after too many attempts', function () {
    User::factory()->create([
        'email' => 'throttle@example.com',
        'password' => Hash::make('Password1'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'throttle@example.com',
        'password' => 'WrongPass1',
    ])->assertStatus(401);

    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'throttle@example.com',
            'password' => 'WrongPass1',
        ]);
    }

    $response->assertStatus(429);
});

// 9. GET /me without token -> 401; with token -> 200
it('returns 401 without token and 200 with valid token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);

    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

// 10. Role middleware blocks consumer from performer route
it('blocks consumer from performer-only route', function () {
    $consumer = User::factory()->create(['role' => 'consumer']);
    $token = $consumer->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/performer/dashboard', ['Authorization' => "Bearer $token"])
        ->assertStatus(403);

    auth()->forgetGuards();

    $performer = User::factory()->performer()->create();
    $performerToken = $performer->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/performer/dashboard', ['Authorization' => "Bearer $performerToken"])
        ->assertOk();
});

// 11. Logout revokes token
it('revokes token on logout so next request is 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer $token"])
        ->assertOk();

    auth()->forgetGuards();

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])
        ->assertStatus(401);
});

// 12. Password reset flow works
it('completes the password reset flow', function () {
    $user = User::factory()->create([
        'email' => 'reset@example.com',
        'password' => Hash::make('OldPassword1'),
    ]);

    $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'reset@example.com',
    ])->assertOk();

    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('NewPassword1', $user->password))->toBeTrue();
});
