<?php

use App\Models\AuditLog;
use App\Models\ContentFlag;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Estatísticas de moderação (Fase 5). Read-only, tudo derivado do que já é
 * registrado (denúncias, flags, audit) — nenhuma coluna nova. Cobre: o gate
 * (moderador/admin entram; membro/performer não), o seletor de período
 * (7/30/90, default 30, valor inválido cai em 30), e o cálculo das métricas —
 * tempo médio, SLA, volume por prioridade, ações por moderador e a taxa de
 * reversão ESTIMADA. Helpers com prefixo stat* para o arquivo rodar isolado.
 */
function statModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

/** Uma performer-alvo (as denúncias de teste apontam para o perfil dela). */
function statTarget(): PerformerProfile
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

/**
 * Abre uma denúncia e a FECHA, com created_at/reviewed_at controlados para
 * medir tempo de resolução e SLA. `open()` deriva a prioridade do motivo.
 */
function statClosedReport(string $reason, Carbon $openedAt, Carbon $reviewedAt): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $report = Report::open($reporter, statTarget(), $reason);

    $report->forceFill([
        'created_at' => $openedAt,
        'status' => 'resolved',
        'reviewed_at' => $reviewedAt,
    ])->save();

    return $report;
}

/**
 * Cria uma linha de audit. `created_at` NÃO está no $fillable do AuditLog, então
 * um `create(['created_at' => ...])` seria descartado e a linha cairia em now();
 * quando o teste precisa de uma data (fora da janela), grava-a por forceFill.
 */
function statAudit(int $userId, string $action, int $subjectId, ?Carbon $createdAt = null): AuditLog
{
    $log = AuditLog::create(['user_id' => $userId, 'action' => $action, 'subject_id' => $subjectId]);

    if ($createdAt !== null) {
        $log->forceFill(['created_at' => $createdAt])->save();
    }

    return $log;
}

it('forbids consumers and performers, redirects guests', function () {
    // Visitante deslogado PRIMEIRO — um actingAs "gruda" nas requests seguintes
    // do mesmo teste, então checar o guest depois do loop daria 403 (gate), não
    // o redirect de login.
    $this->get(route('moderacao.estatisticas'))->assertRedirect(route('login'));

    foreach (['consumer', 'performer'] as $role) {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->actingAs($user)->get(route('moderacao.estatisticas'))->assertForbidden();
    }
});

it('lets a moderator open the stats page with the default 30-day window', function () {
    $this->actingAs(statModerator())
        ->get(route('moderacao.estatisticas'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Stats')
            ->where('dias', 30)
            ->where('metrics.resolved', 0)
            ->where('metrics.avg_resolution_hours', null)
            ->where('metrics.sla_pct', null)
            ->where('metrics.reversal.pct', null)
            ->where('metrics.reversal.exact_pct', null)
            ->where('metrics.reversal.reopened', 0)
            ->where('metrics.reversal.decisions', 0));
});

it('accepts 7/30/90 as the period and falls back to 30 for anything else', function () {
    $mod = statModerator();

    foreach ([7, 30, 90] as $valid) {
        $this->actingAs($mod)->get(route('moderacao.estatisticas', ['dias' => $valid]))
            ->assertInertia(fn (Assert $page) => $page->where('dias', $valid));
    }

    foreach (['999', '0', 'abc', '15'] as $bad) {
        $this->actingAs($mod)->get(route('moderacao.estatisticas', ['dias' => $bad]))
            ->assertInertia(fn (Assert $page) => $page->where('dias', 30));
    }
});

it('computes average resolution time and the SLA percentage', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    // Dentro do SLA: spam = normal (72h). Aberta 1h atrás, fechada agora → 1h.
    statClosedReport('spam', now()->subHours(1), now());
    // Fora do SLA: non_consensual = urgent (4h). Aberta 10h atrás → 10h > 4h.
    statClosedReport('non_consensual', now()->subHours(10), now());

    // Média = (1 + 10) / 2 = 5,5h. Uma das duas dentro do prazo → 50%.
    $this->actingAs(statModerator())
        ->get(route('moderacao.estatisticas', ['dias' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.resolved', 2)
            ->where('metrics.avg_resolution_hours', 5.5)
            ->where('metrics.sla_pct', 50));

    Carbon::setTestNow();
});

it('ignores reports resolved before the window', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    // Fechada há 40 dias: fora da janela de 30 dias, não conta.
    statClosedReport('spam', now()->subDays(40)->subHours(2), now()->subDays(40));
    // Fechada há 2 dias: dentro.
    statClosedReport('spam', now()->subDays(2)->subHours(3), now()->subDays(2));

    $this->actingAs(statModerator())
        ->get(route('moderacao.estatisticas', ['dias' => 30]))
        ->assertInertia(fn (Assert $page) => $page->where('metrics.resolved', 1));

    Carbon::setTestNow();
});

it('counts received reports by priority', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    statClosedReport('non_consensual', now()->subHours(2), now()); // urgent
    statClosedReport('impersonation', now()->subHours(2), now());  // high
    statClosedReport('spam', now()->subHours(2), now());           // normal
    statClosedReport('other', now()->subHours(2), now());          // normal

    $this->actingAs(statModerator())
        ->get(route('moderacao.estatisticas'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.received', 4)
            ->where('metrics.received_by_priority.urgent', 1)
            ->where('metrics.received_by_priority.high', 1)
            ->where('metrics.received_by_priority.normal', 2));

    Carbon::setTestNow();
});

it('aggregates moderation actions per moderator from the audit log', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    $alice = User::factory()->create(['role' => 'moderator', 'status' => 'active', 'name' => 'Alice']);
    $bob = User::factory()->create(['role' => 'moderator', 'status' => 'active', 'name' => 'Bob']);

    // Alice: 3 ações; Bob: 1. Uma ação fora da janela não conta.
    foreach (range(1, 3) as $i) {
        statAudit($alice->id, 'moderation.report_reviewed', 100 + $i);
    }
    statAudit($bob->id, 'moderator.warned', 200);
    statAudit($bob->id, 'moderator.warned', 201, now()->subDays(45));

    $this->actingAs(statModerator())
        ->get(route('moderacao.estatisticas', ['dias' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total_actions', 4)
            ->where('metrics.by_moderator', fn ($rows) => $rows->count() === 2
                && $rows[0]['name'] === 'Alice' && $rows[0]['count'] === 3
                && $rows[1]['name'] === 'Bob' && $rows[1]['count'] === 1));

    Carbon::setTestNow();
});

it('estimates the reversal rate from re-decided reports and early reactivations', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    $mod = statModerator();

    // Duas denúncias revistas; UMA delas foi re-decidida (2 reviews no mesmo alvo).
    statAudit($mod->id, 'moderation.report_reviewed', 500);
    statAudit($mod->id, 'moderation.report_reviewed', 500);
    statAudit($mod->id, 'moderation.report_reviewed', 501);

    // Duas suspensões; UMA reativação cedo por admin.
    statAudit($mod->id, 'member.suspended', 700);
    statAudit($mod->id, 'moderator.suspended', 701);
    statAudit($mod->id, 'member.reactivated', 700);

    // Numerador = redecididas (1) + reativadas (1) = 2.
    // Denominador = revistas distintas (2) + suspensões (2) = 4 → 50%.
    $this->actingAs($mod)
        ->get(route('moderacao.estatisticas', ['dias' => 30]))
        // pct = round(50.0, 1): um float inteiro volta como int 50 depois do
        // round-trip JSON do Inertia (assertSame é estrito com tipo).
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.reversal.pct', 50)
            ->where('metrics.reversal.redecided_reports', 1)
            ->where('metrics.reversal.reviewed_reports', 2)
            ->where('metrics.reversal.reactivated', 1)
            ->where('metrics.reversal.suspensions', 2));

    Carbon::setTestNow();
});

it('computes the EXACT reversal rate by cohort (reopened ⊆ decided, never > 100%)', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    $mod = statModerator();

    // Quatro denúncias DECIDIDAS na janela (cada fechamento loga report_reviewed)…
    foreach (range(1, 4) as $i) {
        statAudit($mod->id, 'moderation.report_reviewed', 800 + $i);
    }
    // …UMA delas foi reaberta → coorte: 1 de 4 = 25% exato.
    statAudit($mod->id, 'moderation.report_reopened', 801);
    // Reabertura de uma denúncia FORA da coorte (não decidida na janela) não
    // conta — o numerador é subconjunto do denominador, então nunca estoura 100%.
    statAudit($mod->id, 'moderation.report_reopened', 999);

    $this->actingAs($mod)
        ->get(route('moderacao.estatisticas', ['dias' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.reversal.reopened', 1)
            ->where('metrics.reversal.decisions', 4)
            // 25.0 → int 25 depois do round-trip JSON do Inertia (assertSame estrito).
            ->where('metrics.reversal.exact_pct', 25));

    Carbon::setTestNow();
});

it('computes the average time to dismiss an automatic content flag', function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    $flagged = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    $mod = statModerator();

    // Dispensado 2h após criado.
    $flag = new ContentFlag;
    $flag->forceFill([
        'user_id' => $flagged->id,
        'source' => ContentFlag::SOURCE_CHAT,
        'category' => ContentFlag::CATEGORY_CONDUCT,
        'rule_hash' => str_repeat('a', 64),
        'status' => ContentFlag::STATUS_DISMISSED,
        'created_at' => now()->subHours(2),
        'reviewed_at' => now(),
        'reviewed_by' => $mod->id,
    ])->save();

    $this->actingAs($mod)
        ->get(route('moderacao.estatisticas'))
        // 2.0h → int 2 depois do round-trip JSON (mesmo motivo do reversal.pct).
        ->assertInertia(fn (Assert $page) => $page->where('metrics.flag_avg_resolution_hours', 2));

    Carbon::setTestNow();
});
