<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Ban permanente de conta (`status = 'banned'`). Cobre a porta admin
 * (UserBanController) e o bloqueio de login nas duas portas de auth. O enum
 * `banned` e o bloqueio em AuthService já existiam (Sprint anterior / QA);
 * o novo é a mensagem específica e o endpoint de moderação.
 * Helpers com prefixo ban* para o arquivo rodar isolado.
 */
function banAdmin(): User
{
    return User::factory()->admin()->create();
}

function banTarget(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'consumer',
        'status' => 'active',
        'password' => Hash::make('Password1'),
    ], $overrides));
}

// ─── Bloqueio de login: banned vs suspended (mensagem distinta) ──────────────

it('blocks web login for a banned user with the permanent-closure message', function () {
    banTarget(['email' => 'banned@web.test', 'status' => 'banned']);

    $response = $this->post('/login', ['email' => 'banned@web.test', 'password' => 'Password1']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('permanentemente encerrada');
    $this->assertGuest();
});

it('blocks web login for a suspended user with a different (suspension) message', function () {
    banTarget(['email' => 'sus@web.test', 'status' => 'suspended']);

    $this->post('/login', ['email' => 'sus@web.test', 'password' => 'Password1'])
        ->assertSessionHasErrors('email');

    // A mensagem de suspensão NÃO diz "permanentemente encerrada".
    $msg = session('errors')->first('email');
    expect($msg)->toContain('suspensa')
        ->and($msg)->not->toContain('permanentemente');
});

it('wrong password on a banned account still gets the generic message (no status leak)', function () {
    banTarget(['email' => 'banned2@web.test', 'status' => 'banned']);

    // Senha errada nunca chega ao ponto que distingue o status: mensagem genérica.
    $this->post('/login', ['email' => 'banned2@web.test', 'password' => 'WrongPass9'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))
        ->toContain('Credenciais inválidas')
        ->not->toContain('permanentemente');
});

it('blocks api login for a banned user (401) with the permanent-closure message', function () {
    banTarget(['email' => 'banned@api.test', 'status' => 'banned']);

    $this->postJson('/api/v1/auth/login', ['email' => 'banned@api.test', 'password' => 'Password1'])
        ->assertStatus(401)
        ->assertJsonFragment(['message' => 'Sua conta foi permanentemente encerrada.']);
});

// ─── Endpoint admin de ban ───────────────────────────────────────────────────

it('lets an admin ban a user with a reason', function () {
    $admin = banAdmin();
    $target = banTarget();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $target), ['reason' => 'Conteúdo proibido reincidente.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($target->fresh()->status)->toBe('banned')
        ->and($target->fresh()->isBanned())->toBeTrue();
});

it('writes an audit log for the ban with reason and banned_by', function () {
    $admin = banAdmin();
    $target = banTarget();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $target), ['reason' => 'Coação denunciada.']);

    $log = AuditLog::where('action', 'user.banned')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($target->id)
        ->and($log->metadata['reason'])->toBe('Coação denunciada.')
        ->and($log->metadata['banned_by'])->toBe($admin->id);
});

it('revokes the banned user active API tokens', function () {
    $admin = banAdmin();
    $target = banTarget();
    $target->createToken('api');

    expect($target->tokens()->count())->toBe(1);

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $target), ['reason' => 'Fraude.']);

    expect($target->fresh()->tokens()->count())->toBe(0);
});

it('requires a reason to ban', function () {
    $admin = banAdmin();
    $target = banTarget();

    $this->actingAs($admin)
        ->from(route('admin.reports'))
        ->post(route('admin.users.ban', $target), [])
        ->assertRedirect(route('admin.reports'))
        ->assertSessionHasErrors('reason');

    expect($target->fresh()->status)->toBe('active');
});

it('short-circuits banning an already-banned user (no duplicate audit)', function () {
    $admin = banAdmin();
    $target = banTarget(['status' => 'banned']);

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $target), ['reason' => 'De novo.'])
        ->assertSessionHas('info')
        ->assertSessionMissing('success');

    expect(AuditLog::where('action', 'user.banned')->count())->toBe(0);
});

it('forbids an admin from banning themselves', function () {
    $admin = banAdmin();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $admin), ['reason' => 'oops'])
        ->assertForbidden();

    expect($admin->fresh()->status)->toBe('active');
});

it('forbids banning another admin through this endpoint', function () {
    $admin = banAdmin();
    $otherAdmin = banAdmin();

    $this->actingAs($admin)
        ->post(route('admin.users.ban', $otherAdmin), ['reason' => 'nope'])
        ->assertForbidden();

    expect($otherAdmin->fresh()->status)->toBe('active');
});

it('denies the ban action to non-admins', function () {
    $target = banTarget();
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($member)
        ->post(route('admin.users.ban', $target), ['reason' => 'x'])
        ->assertForbidden();

    // A própria performer também não bane ninguém.
    $performer = User::factory()->performer()->create(['status' => 'active']);
    $this->actingAs($performer)
        ->post(route('admin.users.ban', $target), ['reason' => 'x'])
        ->assertForbidden();

    expect($target->fresh()->status)->toBe('active');
});

// ─── Sessão web viva morre no ban ────────────────────────────────────────────

it('logs out a banned user with a live web session on the next request', function () {
    $user = banTarget();

    // Sessão viva (autenticada antes do ban).
    $this->actingAs($user)->get(route('catalog'))->assertOk();

    // Banimento acontece enquanto a sessão está aberta.
    $user->forceFill(['status' => 'banned'])->save();

    // Próximo request: middleware derruba a sessão e manda para o login.
    $this->actingAs($user)
        ->get(route('catalog'))
        ->assertRedirect(route('login'));
});

it('does NOT log out a suspended user (keeps the 403 gate behavior)', function () {
    // suspended segue com tratamento por gate (403 por área), não logout — o
    // middleware é banned-only. Aqui só garantimos que ele NÃO redireciona.
    $performer = User::factory()->performer()->create(['status' => 'suspended']);

    $this->actingAs($performer)
        ->get(route('performer.dashboard'))
        ->assertForbidden();
});

// ─── Mass assignment ─────────────────────────────────────────────────────────

it('never accepts status=banned through mass assignment', function () {
    $user = banTarget();

    // fill() com input cru não pode alcançar `status`.
    $user->fill(['status' => 'banned', 'name' => 'Novo Nome']);

    expect($user->status)->toBe('active')
        ->and($user->name)->toBe('Novo Nome')
        ->and($user->getFillable())->not->toContain('status');
});
