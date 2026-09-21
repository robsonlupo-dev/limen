<?php

use App\Models\Report;
use App\Models\User;
use App\Models\Warning;
use App\Services\DocumentAcceptanceService;
use App\Services\MemberNicknameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Denúncia de apelido de membro pela performer (feat/nickname-report, Fase 4b).
 * Reusa o Report (denunciável = o MEMBRO), por STRING pública do apelido, numa
 * porta dedicada. Helpers com prefixo nr*.
 */
function nrPerformer(): User
{
    $u = User::factory()->create(['role' => 'performer', 'status' => 'active', 'email_verified_at' => now()]);
    $u->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(8),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);

    // A área de membros exige aceite dos documentos (grupo-pai documents.accepted).
    app(DocumentAcceptanceService::class)->acceptAll($u, Request::create('/', 'POST'));

    return $u;
}

function nrMember(string $nick = 'Comandante'): User
{
    $m = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $m->forceFill([
        'nickname' => $nick,
        'nickname_normalized' => MemberNicknameService::normalizeUnique($nick),
    ])->save();

    return $m;
}

it('lets a performer report a member nickname', function () {
    $performer = nrPerformer();
    $member = nrMember('Comandante');

    $this->actingAs($performer)
        ->post(route('performer.members.report-nickname'), [
            'nickname' => 'Comandante',
            'reason' => 'impersonation',
            'details' => 'se passa por outra pessoa',
        ])
        ->assertRedirect();

    $report = Report::first();
    expect($report)->not->toBeNull()
        ->and($report->reporter_id)->toBe($performer->id)
        ->and($report->reportable_id)->toBe($member->id)
        ->and($report->reportable_type)->toBe($member->getMorphClass())
        ->and($report->reason)->toBe('impersonation')
        ->and($report->details)->toContain('Comandante')
        ->and($report->priority)->toBe('high'); // impersonation → high
    expect(Report::aliasForClass($report->reportable_type))->toBe('member_nickname');
});

it('answers uniformly for an unknown nickname and creates no report', function () {
    $this->actingAs(nrPerformer())
        ->post(route('performer.members.report-nickname'), ['nickname' => 'NaoExiste', 'reason' => 'spam'])
        ->assertRedirect();

    expect(Report::count())->toBe(0);
});

it('does not create a duplicate report within the window', function () {
    $performer = nrPerformer();
    nrMember('Comandante');
    $payload = ['nickname' => 'Comandante', 'reason' => 'spam'];

    $this->actingAs($performer)->post(route('performer.members.report-nickname'), $payload);
    $this->actingAs($performer)->post(route('performer.members.report-nickname'), $payload);

    expect(Report::count())->toBe(1);
});

it('forbids a non-performer from reporting a nickname', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    nrMember('Comandante');

    $this->actingAs($consumer)
        ->post(route('performer.members.report-nickname'), ['nickname' => 'Comandante', 'reason' => 'spam'])
        ->assertForbidden();

    expect(Report::count())->toBe(0);
});

it('closes the generic report path for member_nickname (no user enumeration)', function () {
    // O oráculo de enumeração de contas fica fechado: a porta genérica nunca
    // resolve um apelido por id de User, e a visibilidade é sempre falsa — então
    // um report.store com este tipo cai no "não encontrado" uniforme.
    $performer = nrPerformer();
    $member = nrMember('Comandante');

    expect(Report::resolveFromHandle('member_nickname', $member->id, $performer))->toBeNull()
        ->and(Report::visibleTo($member, $performer))->toBeFalse();
});

it('lets a moderator act on the reported member (ownerIdOf resolves the user)', function () {
    $performer = nrPerformer();
    $member = nrMember('Comandante');

    $this->actingAs($performer)->post(route('performer.members.report-nickname'), [
        'nickname' => 'Comandante', 'reason' => 'impersonation',
    ]);
    $report = Report::first();

    $mod = User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
    $this->actingAs($mod)
        ->post(route('moderacao.reports.warn', $report), ['reason' => 'apelido ofensivo'])
        ->assertRedirect();

    expect(Warning::where('user_id', $member->id)->exists())->toBeTrue();
});
