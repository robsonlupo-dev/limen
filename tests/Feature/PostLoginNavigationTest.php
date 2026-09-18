<?php

use App\Models\User;
use App\Services\OtpService;
use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Regressão dos dois bugs pós-login relatados em produção (após #227/#228):
 *
 *  1. Admin loga e o painel BLADE aparece como camada SOBRE o /login (URL não
 *     muda), porque o cliente Inertia seguia por XHR um 302 para uma página que
 *     não é Inertia e caía no modal de erro. O redirect para /admin/* agora é
 *     `Inertia::location` (navegação de página inteira → 409 + X-Inertia-Location
 *     para o cliente Inertia).
 *
 *  2. Moderador toma 403: um `url.intended` de /admin/dashboard (capturado pelo
 *     middleware `auth` quando o navegador tocou uma URL admin deslogado)
 *     sobrepunha a fila de moderação e o jogava contra `admin.access`. Não-admin
 *     nunca mais é mandado para /admin/*.
 *
 * Diferente do LoginRedirectAdminTest (que só afere o LOCAL do redirect de um
 * POST comum), aqui exercitamos o CLIENTE INERTIA e seguimos até a página final.
 */

function postLoginOtpFor(User $user): string
{
    return OtpCode::where('user_id', $user->id)->orderByDesc('id')->firstOrFail()->code;
}

// ─── Bug 1: admin não cai no modal do Inertia ────────────────────────────────

it('admin login from the Inertia client forces a full-page visit to the Blade panel', function () {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'admin',
        'status' => 'active',
    ]);

    $login = $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'Senha123',
    ], ['X-Inertia' => 'true']);

    // Navegação de página inteira, NÃO um 302 que o XHR do Inertia seguiria para
    // dentro do modal de erro.
    $login->assertStatus(409);
    expect($login->headers->get('X-Inertia-Location'))->toContain('/admin/dashboard');
    $this->assertAuthenticatedAs($admin);
});

it('a plain (non-Inertia) admin login still gets a 302 to the dashboard', function () {
    $admin = User::factory()->create([
        'email' => 'admin2@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'admin',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'admin2@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('admin.dashboard'));
});

it('the authenticated admin session can load the Blade dashboard with 200', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
    ]);

    $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
});

// ─── Bug 2: moderador não toma 403 ───────────────────────────────────────────

it('moderator reaches the moderation queue with 200, not 403', function () {
    $moderator = User::factory()->create([
        'email' => 'mod@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'moderator',
        'status' => 'active',
    ]);

    $this->post('/login', [
        'email' => 'mod@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(route('moderacao.reports.index'));

    $this->assertAuthenticatedAs($moderator);
    $this->get('/moderacao/denuncias')->assertOk();
});

it('a stale intended admin URL does NOT bounce the moderator into a 403', function () {
    $moderator = User::factory()->create([
        'email' => 'mod2@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'moderator',
        'status' => 'active',
    ]);

    // O que o middleware `auth` faz quando um convidado tenta abrir /admin/*.
    $login = $this->withSession(['url.intended' => url('/admin/dashboard')])->post('/login', [
        'email' => 'mod2@example.com',
        'password' => 'Senha123',
    ]);

    // Volta para a fila de moderação, não para o painel admin.
    $login->assertRedirect(route('moderacao.reports.index'));
    $this->followRedirects($login)->assertOk();
});

it('the same intended override is fixed on the OTP door too', function () {
    Mail::fake();
    $moderator = User::factory()->create([
        'email' => 'mod-otp@example.com',
        'role' => 'moderator',
        'status' => 'active',
    ]);
    app(OtpService::class)->requestCode($moderator->email);
    $code = postLoginOtpFor($moderator);

    $this->withSession([
        'otp_email' => $moderator->email,
        'url.intended' => url('/admin/dashboard'),
    ])->post('/verificar-codigo', ['email' => $moderator->email, 'code' => $code])
        ->assertRedirect(route('moderacao.reports.index'));
});

// ─── Não-regressão: intended legítimo de não-admin é preservado ──────────────

it('preserves a legitimate non-admin intended URL', function () {
    $consumer = User::factory()->create([
        'email' => 'consumer@example.com',
        'password' => bcrypt('Senha123'),
        'role' => 'consumer',
        'status' => 'active',
    ]);

    $this->withSession(['url.intended' => url('/favoritos')])->post('/login', [
        'email' => 'consumer@example.com',
        'password' => 'Senha123',
    ])->assertRedirect(url('/favoritos'));
});
