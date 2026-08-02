<?php

use App\Models\AuditLog;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Str;

/**
 * Refactor de roles (Sprint 13): separa `moderator` de `admin`.
 *
 * A fila `/moderacao/*` deixa passar moderator OU admin, mas NÃO concede os
 * poderes de admin (KYC, payout, ban, tier seguem em /admin/* sob role:admin).
 *
 * Helpers locais (prefixo mod*) para o arquivo rodar isolado ou na suíte.
 */
function mrtModerator(): User
{
    return User::factory()->create([
        'role' => 'moderator',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

function mrtAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'status' => 'active']);
}

function mrtConsumer(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function mrtPerformerProfile(): PerformerProfile
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

function mrtReport(?User $reporter = null): Report
{
    $reporter ??= mrtConsumer();
    $profile = mrtPerformerProfile();

    return Report::open($reporter, $profile, 'spam', 'conteúdo suspeito');
}

// ─── Acesso à fila ───────────────────────────────────────────────────────────

it('lets a moderator open the moderation queue', function () {
    $this->actingAs(mrtModerator())
        ->get(route('moderacao.reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Moderacao/Reports/Index'));
});

it('lets an admin open the moderation queue', function () {
    $this->actingAs(mrtAdmin())
        ->get(route('moderacao.reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Moderacao/Reports/Index'));
});

it('denies the moderation queue to a consumer', function () {
    $this->actingAs(mrtConsumer())
        ->get(route('moderacao.reports.index'))
        ->assertForbidden();
});

it('denies the moderation queue to a performer', function () {
    $performer = mrtPerformerProfile()->user;

    $this->actingAs($performer)
        ->get(route('moderacao.reports.index'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(route('moderacao.reports.index'))
        ->assertRedirect(route('login'));
});

// ─── Privacidade: denunciante pseudonimizado ─────────────────────────────────

it('never shows the reporter raw identity in the queue', function () {
    $reporter = mrtConsumer();
    mrtReport($reporter);

    $this->actingAs(mrtModerator())
        ->get(route('moderacao.reports.index'))
        ->assertOk()
        ->assertDontSee($reporter->email)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Reports/Index')
            ->where('reports.data.0.reporter', fn ($label) => str_starts_with($label, 'Denunciante #'))
            ->missing('reports.data.0.reporter_id')
        );
});

it('shows a report detail to the moderator', function () {
    $report = mrtReport();

    $this->actingAs(mrtModerator())
        ->get(route('moderacao.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Reports/Show')
            ->where('report.id', $report->id)
            ->where('report.reason', 'spam')
        );
});

// ─── Fechamento da denúncia ──────────────────────────────────────────────────

it('lets a moderator resolve a report and records who reviewed it', function () {
    $moderator = mrtModerator();
    $report = mrtReport();

    $this->actingAs($moderator)
        ->patch(route('moderacao.reports.update', $report), [
            'status' => 'resolved',
            'moderator_notes' => 'perfil removido',
        ])
        ->assertRedirect(route('moderacao.reports.show', $report));

    $report->refresh();

    expect($report->status)->toBe('resolved')
        ->and($report->moderator_notes)->toBe('perfil removido')
        ->and($report->reviewed_by)->toBe($moderator->id)
        ->and($report->reviewed_at)->not->toBeNull();
});

it('writes an audit log when a moderator reviews a report', function () {
    $moderator = mrtModerator();
    $report = mrtReport();

    $this->actingAs($moderator)
        ->patch(route('moderacao.reports.update', $report), ['status' => 'dismissed']);

    $log = AuditLog::where('action', 'moderation.report_reviewed')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($moderator->id)
        ->and($log->subject_id)->toBe($report->id)
        ->and($log->metadata['status'])->toBe('dismissed');
});

it('rejects an invalid status on update', function () {
    $report = mrtReport();

    $this->actingAs(mrtModerator())
        ->patch(route('moderacao.reports.update', $report), ['status' => 'banned'])
        ->assertSessionHasErrors('status');

    expect($report->fresh()->status)->toBe('pending');
});

// ─── Fronteira: o moderador NÃO tem poderes de admin ─────────────────────────

it('denies the admin report queue to a moderator', function () {
    $this->actingAs(mrtModerator())
        ->get(route('admin.reports'))
        ->assertForbidden();
});

it('denies the admin kyc panel to a moderator', function () {
    $this->actingAs(mrtModerator())
        ->get(route('admin.kyc.panel'))
        ->assertForbidden();
});

it('does not let a moderator ban an account', function () {
    // Alvo EXISTENTE de propósito: SubstituteBindings roda antes do role:admin,
    // então um id inexistente levaria 404 do binding e passaria sem exercitar o
    // gate (armadilha registrada no CLAUDE.md).
    $target = mrtConsumer();

    $this->actingAs(mrtModerator())
        ->post(route('admin.users.ban', $target))
        ->assertForbidden();

    expect($target->fresh()->status)->toBe('active');
});

// ─── Comando de criação ──────────────────────────────────────────────────────

it('creates a moderator via the artisan command', function () {
    $this->artisan('limen:create-moderator', [
        'email' => 'Mod@Limen.dev.br',
        'name' => 'Fulana Moderadora',
    ])->assertExitCode(0);

    // E-mail normalizado (lowercase/trim), como o OtpService procura no login.
    $user = User::where('email', 'mod@limen.dev.br')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('moderator')
        ->and($user->status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->performerProfile)->toBeNull();

    $log = AuditLog::where('action', 'moderation.moderator_created')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($user->id);
});

it('refuses to create a moderator with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@limen.dev.br']);

    $this->artisan('limen:create-moderator', [
        'email' => 'taken@limen.dev.br',
        'name' => 'Outro',
    ])->assertExitCode(1);
});
