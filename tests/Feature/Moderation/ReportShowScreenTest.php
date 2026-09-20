<?php

use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use App\Services\ModeratorActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Tela de trabalho do moderador (Fase 2): o detalhe da denúncia carrega, além do
 * report+evidence, a FILA ("próximos") e o RODAPÉ DE STATS, e reflete o estado de
 * escalação. Helpers com prefixo rss* para rodar isolado.
 */
function rssModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function rssProfile(): PerformerProfile
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

function rssReport(): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    return Report::open($reporter, rssProfile(), 'spam', 'conteúdo suspeito');
}

it('feeds the report detail with queue and stats', function () {
    $current = rssReport();
    // Outras pendentes: entram em "próximos na fila".
    $other1 = rssReport();
    $other2 = rssReport();

    $this->actingAs(rssModerator())
        ->get(route('moderacao.reports.show', $current))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Reports/Show')
            ->where('report.id', $current->id)
            ->where('report.escalated_at', null)
            // A fila traz as OUTRAS pendentes, nunca a atual.
            ->has('queue', 2)
            ->where('queue.0.id', fn ($id) => in_array($id, [$other1->id, $other2->id], true))
            ->has('stats.pending')
            ->has('stats.resolved_today')
            ->has('stats.escalated')
            ->has('stats.oldest_pending_at')
        );
});

it('excludes the current report from the queue', function () {
    $only = rssReport();

    $this->actingAs(rssModerator())
        ->get(route('moderacao.reports.show', $only))
        ->assertInertia(fn (Assert $page) => $page->has('queue', 0));
});

it('reflects an escalated report and counts it in stats', function () {
    $report = rssReport();
    $moderator = rssModerator();

    app(ModeratorActionService::class)->escalate($report, $moderator, 'merece ban');

    $this->actingAs($moderator)
        ->get(route('moderacao.reports.show', $report))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.escalated_at', fn ($v) => $v !== null)
            ->where('stats.escalated', 1)
        );
});
