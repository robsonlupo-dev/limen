<?php

use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Hub de filas + Escalados ao admin + Minhas ações (feat/moderation-queues-hub).
 * Helpers com prefixo rqh* para rodar isolado.
 */
function rqhModerator(): User
{
    return User::factory()->create(['role' => 'moderator', 'status' => 'active', 'email_verified_at' => now()]);
}

function rqhProfile(): PerformerProfile
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

function rqhReport(string $reason = 'spam'): Report
{
    $reporter = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    return Report::open($reporter, rqhProfile(), $reason, 'detalhe');
}

it('feeds the overview hub with queue counts', function () {
    $mod = rqhModerator();

    rqhReport();                 // pendente fresca
    $toEscalate = rqhReport();   // será escalada
    $overdue = rqhReport();      // pendente atrasada
    $overdue->forceFill(['created_at' => now()->subDays(5)])->save();

    // Escala uma (via HTTP, para o audit gravar o moderador).
    $this->actingAs($mod)->post(route('moderacao.reports.escalate', $toEscalate), ['reason' => 'merece ban']);

    $this->actingAs($mod)
        ->get(route('moderacao.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Overview')
            ->where('queues.reports', 3)          // as três seguem pendentes
            ->where('queues.escalated', 1)
            ->where('queues.overdue', 1)          // só a de 5 dias
            ->where('queues.my_actions_today', 1) // a escalação de agora
            ->has('queues.member_photos')
            ->has('queues.voice_intros')
        );
});

it('filters the reports index to escalated ones', function () {
    $mod = rqhModerator();
    $escalated = rqhReport();
    $plain = rqhReport();

    $this->actingAs($mod)->post(route('moderacao.reports.escalate', $escalated), ['reason' => 'x']);

    $this->actingAs($mod)
        ->get(route('moderacao.reports.index', ['escalated' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/Reports/Index')
            ->where('filters.escalated', true)
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $escalated->id)
        );
});

it('shows only the current moderator actions in My Actions', function () {
    $modA = rqhModerator();
    $modB = rqhModerator();
    $report = rqhReport();

    // modA adverte o alvo (gera audit atribuído a modA).
    $this->actingAs($modA)->post(route('moderacao.reports.warn', $report), ['reason' => 'linguagem abusiva']);

    // modA vê a própria ação.
    $this->actingAs($modA)
        ->get(route('moderacao.my-actions'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Moderacao/MyActions')
            ->has('actions', 1)
            ->where('actions.0.action', 'moderator.warned')
        );

    // modB não vê ação de outro moderador.
    $this->actingAs($modB)
        ->get(route('moderacao.my-actions'))
        ->assertInertia(fn (Assert $page) => $page->has('actions', 0));
});

it('denies the queues to non-moderators', function () {
    $consumer = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    $this->actingAs($consumer)->get(route('moderacao.overview'))->assertForbidden();
    $this->actingAs($consumer)->get(route('moderacao.my-actions'))->assertForbidden();
});
