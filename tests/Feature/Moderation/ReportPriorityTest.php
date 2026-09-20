<?php

use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Prioridade + SLA da fila de moderação (feat/moderation-sla-priority).
 * A prioridade é derivada do motivo na abertura; o SLA (atraso) é derivado de
 * created_at. Helpers com prefixo rpt* para rodar isolado.
 */
function rptModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function rptProfile(): PerformerProfile
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

function rptReport(string $reason): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    return Report::open($reporter, rptProfile(), $reason, 'detalhe');
}

it('derives priority from the reason at open', function () {
    expect(rptReport('underage_content')->priority)->toBe('urgent')
        ->and(rptReport('non_consensual')->priority)->toBe('urgent')
        ->and(rptReport('coercion')->priority)->toBe('high')
        ->and(rptReport('impersonation')->priority)->toBe('high')
        ->and(rptReport('spam')->priority)->toBe('normal')
        ->and(rptReport('other')->priority)->toBe('normal');
});

it('keeps priority out of mass assignment (server authority)', function () {
    expect((new Report)->getFillable())->not->toContain('priority');
});

it('marks an open report overdue once past its SLA window', function () {
    // urgent = 4h. Aberta há 5h e ainda pendente → atrasada.
    $urgent = rptReport('underage_content');
    $urgent->forceFill(['created_at' => now()->subHours(5)])->save();
    expect($urgent->fresh()->isOverdue())->toBeTrue();

    // Fresca não está atrasada.
    expect(rptReport('underage_content')->isOverdue())->toBeFalse();

    // normal = 72h. Aberta há 5h NÃO está atrasada (janela maior).
    $normal = rptReport('spam');
    $normal->forceFill(['created_at' => now()->subHours(5)])->save();
    expect($normal->fresh()->isOverdue())->toBeFalse();
});

it('never marks a closed report overdue', function () {
    $r = rptReport('underage_content');
    $r->forceFill(['created_at' => now()->subDays(10), 'status' => 'resolved'])->save();

    expect($r->fresh()->isOverdue())->toBeFalse();
});

it('orders the work queue by priority then age', function () {
    // Atual (a que está aberta na tela) — normal, bem antiga (4d > SLA de 72h).
    $current = rptReport('spam');
    $current->forceFill(['created_at' => now()->subDays(4)])->save();

    // Uma normal antiga e uma urgente recém-aberta: a urgente vem primeiro.
    $oldNormal = rptReport('spam');
    $oldNormal->forceFill(['created_at' => now()->subDays(2)])->save();
    $freshUrgent = rptReport('non_consensual'); // agora

    $this->actingAs(rptModerator())
        ->get(route('moderacao.reports.show', $current))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.0.id', $freshUrgent->id)
            ->where('queue.0.priority', 'urgent')
            ->where('report.priority', 'normal')
            ->has('report.sla_due_at')
            ->where('report.overdue', true) // aberta há 4 dias, normal SLA 72h → atrasada
        );
});

it('counts overdue reports in the stats', function () {
    $overdue = rptReport('non_consensual'); // urgent, 4h
    $overdue->forceFill(['created_at' => now()->subHours(6)])->save();

    $fresh = rptReport('non_consensual'); // urgent, fresca

    $this->actingAs(rptModerator())
        ->get(route('moderacao.reports.show', $fresh))
        ->assertInertia(fn (Assert $page) => $page->where('stats.overdue', 1));
});
