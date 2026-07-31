<?php

use App\Mail\OtpCodeEmail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\DeletionService;
use App\Services\OtpService;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Login passwordless por código OTP (Sprint 11).
 *
 * A regra vive no OtpService; as duas portas (web/sessão e API/Sanctum) só o
 * transportam. Estes testes cobrem o service E os dois pontos de aplicação — a
 * anti-enumeração e o uso único do código são o produto, não detalhe.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

/** O dígito vivo mais recente do usuário. `code` é $hidden, não some do DB. */
function otpCodeFor(User $user): string
{
    return OtpCode::where('user_id', $user->id)->orderByDesc('id')->firstOrFail()->code;
}

function otpPerformerWith2fa(): User
{
    $user = User::factory()->create([
        'role' => 'performer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    // Liga o 2FA de verdade (secret + confirmado), pelo service que é dono dele.
    $service = app(TwoFactorService::class);
    $setup = $service->enable($user);
    $service->confirm($user->fresh(), (new Google2FA)->getCurrentOtp($setup['secret']));

    return $user->fresh();
}

// ─── Service: anti-enumeração e emissão ──────────────────────────────────────

it('sends a code for a real user and stores exactly one live code', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'real@example.com']);

    app(OtpService::class)->requestCode('real@example.com');

    expect(OtpCode::where('user_id', $user->id)->whereNull('used_at')->count())->toBe(1);
    Mail::assertQueued(OtpCodeEmail::class, fn ($m) => $m->hasTo('real@example.com'));
});

it('does not send or store anything for an unknown email, but responds the same', function () {
    Mail::fake();

    app(OtpService::class)->requestCode('nobody@example.com');

    expect(OtpCode::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('never leaks the code into audit_logs', function () {
    Mail::fake();
    $user = User::factory()->create();

    app(OtpService::class)->requestCode($user->email);
    $code = otpCodeFor($user);

    // Nenhuma linha de audit pode carregar o dígito em metadata.
    $rows = DB::table('audit_logs')->pluck('metadata')->filter();
    foreach ($rows as $meta) {
        expect($meta)->not->toContain($code);
    }
    // E o evento de request existe, sem o código.
    $this->assertDatabaseHas('audit_logs', ['action' => 'auth.otp_requested', 'user_id' => $user->id]);
});

it('invalidates prior unused codes when a new one is issued', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $service->requestCode($user->email);
    $first = OtpCode::where('user_id', $user->id)->firstOrFail();

    $service->requestCode($user->email);

    // O primeiro foi apagado; sobra um único vivo.
    expect(OtpCode::find($first->id))->toBeNull()
        ->and(OtpCode::where('user_id', $user->id)->count())->toBe(1);
});

it('does not send a code to suspended or banned accounts', function () {
    Mail::fake();
    $suspended = User::factory()->create(['status' => 'suspended']);
    $banned = User::factory()->create(['status' => 'banned']);

    app(OtpService::class)->requestCode($suspended->email);
    app(OtpService::class)->requestCode($banned->email);

    expect(OtpCode::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('caps requests at 3 per hour per email, counting before the user lookup', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $service->requestCode($user->email);
    $service->requestCode($user->email);
    $service->requestCode($user->email);

    expect(fn () => $service->requestCode($user->email))
        ->toThrow(App\Exceptions\OtpThrottleException::class);
});

it('shares the per-hour cap across case and whitespace variants of the same email', function () {
    // Trava a regressão da 1ª revisão de segurança: a chave do rate limit é
    // derivada de mb_strtolower(trim($email)), então variar caixa/espaços NÃO
    // rende buckets distintos — senão o teto de 3/hora seria contornável e
    // viraria oráculo de enumeração.
    Mail::fake();
    User::factory()->create(['email' => 'user@example.com']);
    $service = app(OtpService::class);

    $service->requestCode('USER@example.com');
    $service->requestCode('  user@example.com  ');
    $service->requestCode('User@Example.com');

    expect(fn () => $service->requestCode('user@example.com'))
        ->toThrow(App\Exceptions\OtpThrottleException::class);
});

it('applies the same throttle to an unknown email (the limit is not an oracle)', function () {
    Mail::fake();
    $service = app(OtpService::class);

    $service->requestCode('ghost@example.com');
    $service->requestCode('ghost@example.com');
    $service->requestCode('ghost@example.com');

    expect(fn () => $service->requestCode('ghost@example.com'))
        ->toThrow(App\Exceptions\OtpThrottleException::class);
});

// ─── Service: verificação ────────────────────────────────────────────────────

it('logs in with the correct code and burns it (single use)', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);
    $service->requestCode($user->email);
    $code = otpCodeFor($user);

    expect($service->verifyCode($user->email, $code)?->id)->toBe($user->id);
    // Segundo uso do mesmo código falha.
    expect($service->verifyCode($user->email, $code))->toBeNull();
});

it('rejects a wrong code and increments attempts', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);
    $service->requestCode($user->email);

    expect($service->verifyCode($user->email, '000000'))->toBeNull();

    $otp = OtpCode::where('user_id', $user->id)->firstOrFail();
    expect($otp->attempts)->toBe(1)->and($otp->used_at)->toBeNull();
});

it('burns the code after MAX_ATTEMPTS wrong guesses, so the correct one no longer works', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);
    $service->requestCode($user->email);
    $code = otpCodeFor($user);
    $wrong = $code === '111111' ? '222222' : '111111';

    for ($i = 0; $i < OtpCode::MAX_ATTEMPTS; $i++) {
        $service->verifyCode($user->email, $wrong);
    }

    // Queimado: nem o dígito certo entra.
    expect($service->verifyCode($user->email, $code))->toBeNull();
});

it('rejects an expired code', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);
    $service->requestCode($user->email);
    $code = otpCodeFor($user);

    DB::table('otp_codes')->where('user_id', $user->id)
        ->update(['expires_at' => now()->subMinute()]);

    expect($service->verifyCode($user->email, $code))->toBeNull();
});

it('returns null for a suspended account even with the right code', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);
    $service->requestCode($user->email);
    $code = otpCodeFor($user);

    DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

    expect($service->verifyCode($user->email, $code))->toBeNull();
});

// ─── Web port ────────────────────────────────────────────────────────────────

it('web: sending a code redirects to the verify screen and stores email in session', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->post('/entrar-com-codigo', ['email' => $user->email])
        ->assertRedirect(route('otp.verify.show'))
        ->assertSessionHas('otp_email', $user->email);
});

it('web: the verify screen redirects back when there is no email in session', function () {
    $this->get('/verificar-codigo')->assertRedirect(route('otp.request.show'));
});

it('web: a correct code logs the user in and lands on the home route', function () {
    Mail::fake();
    $user = User::factory()->create(); // consumer -> catalog
    app(OtpService::class)->requestCode($user->email);
    $code = otpCodeFor($user);

    // O e-mail é lido da SESSÃO, não do corpo — como no fluxo real (sendCode).
    $this->withSession(['otp_email' => $user->email])
        ->post('/verificar-codigo', ['email' => $user->email, 'code' => $code])
        ->assertRedirect(route('catalog'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('web: verify uses the session email, not the (forgeable) request body', function () {
    Mail::fake();
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    app(OtpService::class)->requestCode($victim->email);
    $code = otpCodeFor($victim);

    // Sessão pertence à vítima; o corpo tenta trocar o alvo para o atacante.
    // Como a verificação usa a sessão, o código da vítima não valida contra o
    // atacante e ninguém entra.
    $this->withSession(['otp_email' => $victim->email])
        ->post('/verificar-codigo', ['email' => $attacker->email, 'code' => $code])
        ->assertRedirect(route('catalog'));

    // Autenticou a VÍTIMA (dona da sessão e do código), nunca o atacante.
    $this->assertAuthenticatedAs($victim->fresh());
});

it('web: verify without a session email redirects to the request screen', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);

    $this->post('/verificar-codigo', ['email' => $user->email, 'code' => otpCodeFor($user)])
        ->assertRedirect(route('otp.request.show'));

    $this->assertGuest();
});

it('web: a wrong code fails validation and does not authenticate', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);

    $this->withSession(['otp_email' => $user->email])
        ->from(route('otp.verify.show'))
        ->post('/verificar-codigo', ['email' => $user->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('web: the request screen renders the Inertia page', function () {
    $this->get('/entrar-com-codigo')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/OtpRequest'));
});

// ─── API port ────────────────────────────────────────────────────────────────

it('api: request always returns a generic 200, real email or not', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/otp/request', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/otp/request', ['email' => 'ghost@example.com'])->assertOk();
});

it('api: request returns 429 when the per-email cap is hit', function () {
    Mail::fake();
    $user = User::factory()->create();

    for ($i = 0; $i < OtpService::MAX_PER_HOUR; $i++) {
        app(OtpService::class)->requestCode($user->email);
    }

    $this->postJson('/api/v1/auth/otp/request', ['email' => $user->email])->assertStatus(429);
});

it('api: verify returns a working token on the correct code', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);
    $code = otpCodeFor($user);

    $response = $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code])
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'email'], 'token']);

    $token = $response->json('token');
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"])->assertOk();
});

it('api: verify returns 422 on a wrong code', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);

    $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => '000000'])
        ->assertStatus(422);
});

it('api: verify rejects a malformed code at validation', function () {
    // Curto demais.
    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'x@example.com', 'code' => '12'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    // Seis caracteres, mas não-numéricos: `digits:6` recusa antes do hash_equals.
    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'x@example.com', 'code' => 'abcdef'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

// ─── 2FA interaction ─────────────────────────────────────────────────────────

it('api: a performer with 2FA gets a challenge token, not a full one', function () {
    Mail::fake();
    $user = otpPerformerWith2fa();
    app(OtpService::class)->requestCode($user->email);
    $code = otpCodeFor($user);

    $response = $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code])
        ->assertOk()
        ->assertJson(['two_factor_required' => true])
        ->assertJsonMissing(['token' => true]);

    // O token de desafio não abre uma rota autenticada normal.
    $challenge = $response->json('challenge_token');
    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $challenge"])
        ->assertStatus(403);
});

it('web: OTP login clears any stale 2fa session marker', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);
    $code = otpCodeFor($user);

    $this->withSession([TwoFactorService::SESSION_KEY => 999, 'otp_email' => $user->email])
        ->post('/verificar-codigo', ['email' => $user->email, 'code' => $code])
        ->assertRedirect(route('catalog'));

    expect(session(TwoFactorService::SESSION_KEY))->toBeNull();
});

// ─── Deletion ────────────────────────────────────────────────────────────────

it('otp:purge deletes expired codes and keeps the live ones', function () {
    Mail::fake();
    $stale = User::factory()->create();
    $fresh = User::factory()->create();

    app(OtpService::class)->requestCode($stale->email);
    app(OtpService::class)->requestCode($fresh->email);

    // Vence o código do primeiro user; o do segundo segue vivo.
    DB::table('otp_codes')->where('user_id', $stale->id)
        ->update(['expires_at' => now()->subMinute()]);

    $this->artisan('otp:purge')->assertSuccessful();

    expect(OtpCode::where('user_id', $stale->id)->count())->toBe(0)
        ->and(OtpCode::where('user_id', $fresh->id)->count())->toBe(1);
});

it('hard delete purges the user\'s otp codes', function () {
    Mail::fake();
    $user = User::factory()->create();
    app(OtpService::class)->requestCode($user->email);
    expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);

    app(DeletionService::class)->executeDeletion($user);

    expect(OtpCode::where('user_id', $user->id)->count())->toBe(0);
});
