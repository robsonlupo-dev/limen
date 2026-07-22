<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makePerformerProfile(User $user, array $attrs = []): void
{
    $user->performerProfile()->create(array_merge([
        'stage_name' => 'Ana Lima '.Str::random(4),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
    ], $attrs));
}

// ─── FIX 1 — Entrada ──────────────────────────────────────────────────────────

it('renders the entrada role picker for guests', function () {
    $this->get('/entrada')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Entrada'));
});

it('redirects logged-in users away from entrada to the catalog', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($user)->get('/entrada')->assertRedirect(route('catalog'));
});

// ─── FIX 1/7 — Registration by tipo ──────────────────────────────────────────

it('creates a consumer when registering with tipo=membro', function () {
    $this->post('/cadastro', [
        'tipo' => 'membro',
        'name' => 'Maria Membro',
        'email' => 'maria.membro@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-06-15',
        'cpf' => '529.982.247-25',
        'preferred_world' => 'homens',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('verification.notice'));

    $this->assertDatabaseHas('users', [
        'email' => 'maria.membro@example.com',
        'role' => 'consumer',
        'preferred_world' => 'homens',
    ]);
});

it('creates a performer with a KYC record when registering with tipo=performer', function () {
    $this->post('/cadastro', [
        'tipo' => 'performer',
        'name' => 'Estrela Silva',
        'email' => 'estrela@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1992-03-20',
        'cpf' => '529.982.247-25',
        'stage_name' => 'Estrela',
        'category' => 'trans',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ])->assertRedirect(route('performer.onboarding'));

    $user = User::where('email', 'estrela@example.com')->first();

    expect($user->role)->toBe('performer');
    expect($user->status)->toBe('pending');
    expect($user->performerProfile)->not->toBeNull();
    expect($user->performerProfile->category)->toBe('trans');
    $this->assertDatabaseHas('identity_verifications', [
        'user_id' => $user->id,
        'status' => 'pending',
    ]);
});

it('never allows registering as admin via mass assignment', function () {
    $this->post('/cadastro', [
        'tipo' => 'admin',
        'role' => 'admin',
        'name' => 'Sneaky Admin',
        'email' => 'sneaky@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-01-01',
        'cpf' => '529.982.247-25',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ]);

    $this->assertDatabaseHas('users', ['email' => 'sneaky@example.com', 'role' => 'consumer']);
    $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com', 'role' => 'admin']);
});

// ─── FIX 4 — Email verification ──────────────────────────────────────────────

it('sends a PT-BR verification notification on registration', function () {
    Notification::fake();

    $this->post('/cadastro', [
        'tipo' => 'membro',
        'name' => 'Verify User',
        'email' => 'verify@example.com',
        'password' => 'Senha123',
        'password_confirmation' => 'Senha123',
        'birthdate' => '1990-06-15',
        'cpf' => '529.982.247-25',
        'preferred_world' => 'mulheres',
        'accept_terms' => true,
        'lgpd_consent' => true,
    ]);

    $user = User::where('email', 'verify@example.com')->first();

    Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);

        return $mail->subject === 'Confirme seu e-mail — Limen';
    });
});

it('verifies the email via the web link and redirects to the catalog', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active', 'email_verified_at' => null]);

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('catalog'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

// ─── FIX 5 — Password reset ──────────────────────────────────────────────────

it('sends a PT-BR reset notification from the forgot-password form', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'forgot@example.com']);

    $this->post('/esqueci-minha-senha', ['email' => 'forgot@example.com'])
        ->assertRedirect();

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        return $notification->toMail($user)->subject === 'Redefinição de senha — Limen';
    });
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $token = Password::createToken($user);

    $this->post('/resetar-senha', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'NovaSenha123',
        'password_confirmation' => 'NovaSenha123',
    ])->assertRedirect(route('login'));

    expect(Hash::check('NovaSenha123', $user->fresh()->password))->toBeTrue();
});

// ─── FIX 6 — Catalog world ───────────────────────────────────────────────────

it('defaults the catalog to the members preferred world', function () {
    $consumer = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'homens',
    ]);

    makePerformerProfile(
        User::factory()->create(['role' => 'performer', 'status' => 'active']),
        ['category' => 'homens', 'stage_name' => 'Homem A']
    );
    makePerformerProfile(
        User::factory()->create(['role' => 'performer', 'status' => 'active']),
        ['category' => 'mulheres', 'stage_name' => 'Mulher B']
    );

    $this->actingAs($consumer)
        ->get('/catalogo')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Index')
            ->where('currentWorld', 'homens')
            ->where('userWorld', 'homens')
            ->has('performers.data', 1)
            ->where('performers.data.0.category', 'homens')
        );
});

it('updates the preferred world via the preferences endpoint', function () {
    $consumer = User::factory()->create([
        'role' => 'consumer',
        'status' => 'active',
        'preferred_world' => 'mulheres',
    ]);

    $this->actingAs($consumer)
        ->patch('/preferencias', ['preferred_world' => 'trans'])
        ->assertRedirect(route('catalog'));

    expect($consumer->fresh()->preferred_world)->toBe('trans');
});

it('rejects an invalid preferred world value', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)
        ->patch('/preferencias', ['preferred_world' => 'marte'])
        ->assertSessionHasErrors('preferred_world');
});
