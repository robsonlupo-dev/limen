<?php

use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Destino pós-login por PAPEL (fix/login-redirect-admin).
 *
 * A regra vive no trait `RedirectsToHome::homeRouteFor()`, compartilhado pelas
 * duas portas web (login por senha e login por código). Antes deste fix, admin e
 * moderador caíam no fallback de consumer (`catalog`) e levavam 403, porque o
 * catálogo exige role de consumer. Staff agora vai para o painel admin; consumer,
 * performer ativa e performer em KYC seguem sem regressão.
 */

/** O dígito vivo mais recente do usuário (`code` é $hidden, não some do DB). */
function loginRedirectOtpFor(User $user): string
{
    return OtpCode::where('user_id', $user->id)->orderByDesc('id')->firstOrFail()->code;
}

// ─── Login por senha ─────────────────────────────────────────────────────────

it('sends an admin to the admin dashboard, not the catalog', function () {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'admin',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin);
});

it('sends a moderator to the admin dashboard, not the catalog', function () {
    $moderator = User::factory()->create([
        'email' => 'mod@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'moderator',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'mod@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($moderator);
});

it('still sends a consumer to the catalog', function () {
    $consumer = User::factory()->create([
        'email' => 'consumer@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'consumer@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('catalog'));

    $this->assertAuthenticatedAs($consumer);
});

it('still sends an active performer to the members catalog', function () {
    $performer = User::factory()->create([
        'email' => 'performer@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'performer',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'performer@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('performer.members'));

    $this->assertAuthenticatedAs($performer);
});

it('still sends a performer in KYC to the onboarding', function () {
    $performer = User::factory()->create([
        'email' => 'kyc@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'performer',
        'status' => 'pending_kyc',
    ]);

    $this->post('/login', [
        'email' => 'kyc@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('performer.onboarding'));

    $this->assertAuthenticatedAs($performer);
});

// ─── Login por código (OTP) — mesmo trait, mesmo destino ─────────────────────

it('sends an admin who logs in by OTP to the admin dashboard too', function () {
    Mail::fake();
    $admin = User::factory()->create([
        'email' => 'admin-otp@example.com',
        'role' => 'admin',
        'status' => 'active',
    ]);
    app(OtpService::class)->requestCode($admin->email);
    $code = loginRedirectOtpFor($admin);

    // O e-mail é lido da SESSÃO, como no fluxo real (sendCode).
    $this->withSession(['otp_email' => $admin->email])
        ->post('/verificar-codigo', ['email' => $admin->email, 'code' => $code])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin->fresh());
});
