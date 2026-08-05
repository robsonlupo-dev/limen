<?php

use App\Models\User;
use App\Services\DocumentAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PR #144 (Sprint 15) — dark launch da live/chamada. Em produção
 * FEATURE_LIVE_ENABLED/FEATURE_CALL_ENABLED estão OFF: as flags são compartilhadas
 * como props globais do Inertia (o front decide entre a feature e o placeholder
 * "Em breve") e TODA rota de live/call/group é gateada pelo middleware `feature:*`
 * (403 quando off). A autoridade é o middleware — a prop só pinta a tela.
 */
function flagMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function flagPerformer(): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

// ── Props globais do Inertia ─────────────────────────────────────────────────

it('compartilha features.live_enabled e features.call_enabled como props globais (off por padrão)', function () {
    $member = flagMember();

    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('features.live_enabled', false)
            ->where('features.call_enabled', false));
});

it('reflete as flags quando ligadas', function () {
    config(['features.live_enabled' => true, 'features.call_enabled' => true]);
    $member = flagMember();

    $this->actingAs($member)
        ->get(route('catalog'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('features.live_enabled', true)
            ->where('features.call_enabled', true));
});

// ── Rotas de live gateadas ───────────────────────────────────────────────────

it('rotas de live retornam 403 com a flag off e funcionam com ela on', function () {
    $performer = flagPerformer();
    $member = flagMember();

    // OFF (padrão): o estúdio da performer e o viewer do membro dão 403.
    $this->actingAs($performer)->get(route('performer.live'))->assertForbidden();
    $this->actingAs($member)->getJson(route('live.show', 'qualquer-slug'))->assertForbidden();

    // ON: o estúdio carrega (a página é Inertia pura, sem tocar o LiveKit).
    config(['features.live_enabled' => true]);
    $this->actingAs($performer)
        ->get(route('performer.live'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Performer/Live'));
});

// ── Rotas de call/group gateadas ─────────────────────────────────────────────

it('rotas de chamada retornam 403 com a flag off', function () {
    $performer = flagPerformer();

    // `group.start` e `performer.call.settings` não têm param de modelo, então o
    // gate `feature:call` responde direto (sem 404 de binding antes).
    $this->actingAs($performer)->postJson(route('group.start'), [
        'price_per_minute' => 10, 'max_participants' => 5,
    ])->assertForbidden();

    $this->actingAs($performer)->patchJson(route('performer.call.settings'), [
        'price_per_minute' => 10,
    ])->assertForbidden();
});

it('todas as rotas de live/call/group carregam o middleware feature:* (nenhuma desprotegida)', function () {
    $gated = [];
    foreach (app('router')->getRoutes() as $route) {
        $name = $route->getName();
        if (! $name || ! preg_match('/^(live|call|group|performer\.live|performer\.call)/', $name)) {
            continue;
        }
        $mw = implode(',', $route->gatherMiddleware());
        $gated[$name] = str_contains($mw, 'feature:live') || str_contains($mw, 'feature:call');
    }

    // Há rotas nas famílias e TODAS estão gateadas.
    expect($gated)->not->toBeEmpty()
        ->and(collect($gated)->filter(fn ($ok) => ! $ok)->keys()->all())->toBe([]);
});
