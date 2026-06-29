<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// ─── 1. Landing page renders ─────────────────────────────────────────────────

it('renders landing page as Inertia Landing component', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Landing'));
});

// ─── 2. Register page renders ────────────────────────────────────────────────

it('renders register page as Inertia Auth/Register component', function () {
    $this->get('/cadastro')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
});

// ─── 3. Login page renders ───────────────────────────────────────────────────

it('renders login page as Inertia Auth/Login component', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

// ─── 4. Successful registration ──────────────────────────────────────────────

it('registers a new consumer and redirects to email verification', function () {
    $response = $this->post('/cadastro', [
        'name' => 'Maria Silva',
        'email' => 'maria@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-06-15',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ]);

    $response->assertRedirect(route('verification.notice'));
    $this->assertDatabaseHas('users', ['email' => 'maria@example.com', 'role' => 'consumer']);
    $this->assertAuthenticated();
});

// ─── 5. Minor (<18) is rejected ──────────────────────────────────────────────

it('rejects registration for users under 18 years old', function () {
    $this->post('/cadastro', [
        'name' => 'João Menor',
        'email' => 'menor@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => now()->subYears(17)->format('Y-m-d'),
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertSessionHasErrors('birthdate');

    $this->assertDatabaseMissing('users', ['email' => 'menor@example.com']);
    $this->assertGuest();
});

// ─── 6. Duplicate email is rejected ──────────────────────────────────────────

it('rejects registration with an already-used email', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->post('/cadastro', [
        'name' => 'Outro Nome',
        'email' => 'dup@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1988-01-01',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertSessionHasErrors('email');
});

// ─── 7. Successful login ─────────────────────────────────────────────────────

it('authenticates a consumer and redirects to catalog', function () {
    $user = User::factory()->create([
        'email' => 'consumer@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'consumer@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('catalog'));

    $this->assertAuthenticatedAs($user);
});

// ─── 8. Wrong credentials are rejected ───────────────────────────────────────

it('rejects login with wrong password', function () {
    User::factory()->create([
        'email' => 'consumer@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'consumer@example.com',
        'password' => 'ErradaDemais',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

// ─── 9. Logout clears session ────────────────────────────────────────────────

it('logs out the authenticated user and redirects to landing', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('landing'));

    $this->assertGuest();
});

// ─── 10. Protected catalog redirects guests to login ─────────────────────────

it('redirects unauthenticated access to catalog to the login page', function () {
    $this->get('/catalogo')
        ->assertRedirect(route('login'));
});

// ─── 11. Catalog renders for authenticated user ───────────────────────────────

it('renders catalog page for authenticated consumer', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Catalog'));
});

// ─── 12. Age gate: shared ageAccepted is false without cookie ─────────────────

it('shares ageAccepted=false when the age gate cookie is absent', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('ageAccepted', false));
});

// ─── 13. Age gate: shared ageAccepted is true with accepted cookie ────────────

it('shares ageAccepted=true when the age gate cookie is present', function () {
    $this->withCookie('limen_age', '1')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('ageAccepted', true));
});

// ─── 14. Auth user is shared in Inertia props ────────────────────────────────

it('shares authenticated user data in Inertia props', function () {
    $user = User::factory()->create([
        'name' => 'Teste User',
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get('/catalogo')
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.user')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.role', 'consumer')
            ->missing('auth.user.password')
        );
});

// ─── 15. Guest redirects from login page when already auth'd ──────────────────

it('redirects authenticated users away from login page', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect();
});
