<?php

use App\Models\AuditLog;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ações do moderador (feat/moderator-actions): advertir + suspender temporário
 * (poderes novos) e escalar ao admin. Ban permanente segue exclusivo do admin.
 * Helpers com prefixo mac* para o arquivo rodar isolado.
 */
function macModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function macTargetProfile(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

function macReport(): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    return Report::open($reporter, macTargetProfile(), 'coercion', 'suspeita de coerção');
}

// ─── Advertir ────────────────────────────────────────────────────────────────

it('lets a moderator warn the target of a report', function () {
    $report = macReport();
    $targetId = $report->reportable->user_id;

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.warn', $report), ['reason' => 'linguagem abusiva'])
        ->assertRedirect();

    $warning = Warning::where('user_id', $targetId)->first();
    expect($warning)->not->toBeNull()
        ->and($warning->reason)->toBe('linguagem abusiva')
        ->and($warning->report_id)->toBe($report->id);
    expect(AuditLog::where('action', 'moderator.warned')->where('subject_id', $targetId)->exists())->toBeTrue();
});

it('requires a reason to warn', function () {
    $report = macReport();

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.warn', $report), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(Warning::count())->toBe(0);
});

// ─── Suspender temporário ────────────────────────────────────────────────────

it('lets a moderator suspend the target for N days', function () {
    $report = macReport();
    $targetId = $report->reportable->user_id;

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.suspend', $report), ['reason' => 'assédio', 'days' => 7])
        ->assertRedirect();

    $target = User::find($targetId);
    expect($target->status)->toBe('suspended')
        ->and($target->suspended_until)->not->toBeNull()
        ->and($target->suspended_until->isFuture())->toBeTrue();
    expect(AuditLog::where('action', 'moderator.suspended')->where('subject_id', $targetId)->exists())->toBeTrue();
});

it('requires a reason and days to suspend', function () {
    $report = macReport();

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.suspend', $report), ['reason' => '', 'days' => 7])
        ->assertSessionHasErrors('reason');

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.suspend', $report), ['reason' => 'x'])
        ->assertSessionHasErrors('days');
});

// ─── Expiração automática da suspensão (AuthService) ─────────────────────────

it('blocks login while suspended and auto-lifts once the term passes', function () {
    // Prazo no futuro → barra.
    $future = User::factory()->create([
        'role' => 'consumer', 'email' => 'sus-future@mac.test',
        'password' => Hash::make('Password1'), 'status' => 'suspended',
        'suspended_until' => now()->addDays(3),
    ]);
    $this->post('/login', ['email' => 'sus-future@mac.test', 'password' => 'Password1'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    expect($future->fresh()->status)->toBe('suspended');

    // Prazo no passado → reativa e deixa entrar.
    $past = User::factory()->create([
        'role' => 'consumer', 'email' => 'sus-past@mac.test',
        'password' => Hash::make('Password1'), 'status' => 'suspended',
        'suspended_until' => now()->subDay(),
    ]);
    $this->post('/login', ['email' => 'sus-past@mac.test', 'password' => 'Password1']);
    expect($past->fresh()->status)->toBe('active')
        ->and($past->fresh()->suspended_until)->toBeNull();
});

it('keeps blocking an indefinitely suspended account (suspended_until null)', function () {
    User::factory()->create([
        'role' => 'consumer', 'email' => 'sus-null@mac.test',
        'password' => Hash::make('Password1'), 'status' => 'suspended',
        'suspended_until' => null,
    ]);

    $this->post('/login', ['email' => 'sus-null@mac.test', 'password' => 'Password1'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

// ─── Escalar ao admin ────────────────────────────────────────────────────────

it('lets a moderator escalate a report to the admin', function () {
    $report = macReport();

    $this->actingAs(macModerator())
        ->post(route('moderacao.reports.escalate', $report), ['reason' => 'merece ban'])
        ->assertRedirect();

    $fresh = $report->fresh();
    expect($fresh->escalated_at)->not->toBeNull()
        ->and($fresh->escalated_by)->not->toBeNull();
    expect(Report::escalated()->whereKey($report->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'moderator.escalated')->where('subject_id', $report->id)->exists())->toBeTrue();
});

// ─── Gate: moderador NÃO ganha o ban; não-moderador não alcança as ações ─────

it('still forbids a moderator from banning an account', function () {
    $moderator = macModerator();
    $victim = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    // A rota de ban é do admin (/admin/*); moderador leva 403.
    $this->actingAs($moderator)
        ->post(route('admin.users.ban', $victim), ['reason' => 'x'])
        ->assertForbidden();
});

it('denies the moderator actions to consumer and performer', function () {
    $report = macReport();

    foreach (['consumer', 'performer'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->post(route('moderacao.reports.warn', $report), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('moderacao.reports.suspend', $report), ['reason' => 'x', 'days' => 7])->assertForbidden();
        $this->actingAs($user)->post(route('moderacao.reports.escalate', $report), ['reason' => 'x'])->assertForbidden();
    }
});

// ─── Sessão web VIVA da conta suspensa (BlockSuspendedUsers) ─────────────────
// A suspensão precisa PARAR o dano vivo, não só barrar o próximo login: uma
// sessão aberta na hora da suspensão é derrubada a cada request.

it('kills the live web session of a suspended account', function () {
    // Alvo com sessão viva; qualquer papel serve — o middleware do grupo web roda
    // antes do gate da rota. Prazo no futuro → derruba e manda para o login.
    $suspended = User::factory()->create([
        'role' => 'moderator', 'status' => 'suspended',
        'suspended_until' => now()->addDays(3),
    ]);

    $this->actingAs($suspended)
        ->get(route('moderacao.overview'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($suspended->fresh()->status)->toBe('suspended');
});

it('lets a live session resume once the suspension term passes', function () {
    // Sessão que atravessa o fim do prazo: o middleware reativa e deixa seguir,
    // sem forçar novo login.
    $expired = User::factory()->create([
        'role' => 'moderator', 'status' => 'suspended',
        'suspended_until' => now()->subMinute(),
    ]);

    $this->actingAs($expired)
        ->get(route('moderacao.overview'))
        ->assertOk();

    expect($expired->fresh()->status)->toBe('active')
        ->and($expired->fresh()->suspended_until)->toBeNull();
});
