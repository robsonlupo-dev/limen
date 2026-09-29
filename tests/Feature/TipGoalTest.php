<?php

use App\Models\TipGoal;
use App\Services\TipGoalService;
use App\Services\TipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Metas de gorjeta — roadmap social, Onda 4 (§4.2). Leitura AGREGADA por cima das
 * gorjetas: (1) uma ativa por performer; (2) progresso = soma das gorjetas DESDE o
 * início da meta; (3) anonimato — só soma/alvo, nunca "quem" nem contagem; (4) só a
 * performer dona gerencia. NÃO mexe em token. Prefixo `tg`.
 */
function tgTip($consumer, $profile, int $amount): void
{
    app(TipService::class)->send($consumer, $profile, $amount, (string) Str::uuid());
}

it('cria uma meta ativa e substitui a anterior (uma por vez)', function () {
    $p = chatPerformer();
    $svc = app(TipGoalService::class);

    $first = $svc->set($p, ['title' => 'Setup', 'target' => 500]);
    $second = $svc->set($p, ['title' => 'Viagem', 'target' => 1000]);

    expect(TipGoal::where('performer_profile_id', $p->id)->active()->count())->toBe(1)
        ->and($svc->currentFor($p)->id)->toBe($second->id)
        ->and($first->fresh()->ended_at)->not->toBeNull();
});

it('progresso conta só as gorjetas desde o início da meta', function () {
    $p = chatPerformer();
    $member = chatMember(1000);
    $svc = app(TipGoalService::class);

    // Gorjeta ANTES da meta (não conta).
    $this->travelTo(now()->subMinutes(10));
    tgTip($member, $p, 40);
    $this->travelBack();

    $goal = $svc->set($p, ['title' => 'Setup', 'target' => 200]);

    // Gorjetas DEPOIS do início (contam): 30 + 50 = 80.
    tgTip($member, $p, 30);
    tgTip($member, $p, 50);

    $payload = $svc->publicPayload($p, $goal);
    expect($payload['raised'])->toBe(80)
        ->and($payload['target'])->toBe(200)
        ->and($payload['pct'])->toBe(40); // 80/200
});

it('o pct satura em 100 quando passa do alvo', function () {
    $p = chatPerformer();
    $member = chatMember(1000);
    $svc = app(TipGoalService::class);
    $goal = $svc->set($p, ['title' => 'Meta baixa', 'target' => 50]);

    tgTip($member, $p, 100); // dobro do alvo

    $payload = $svc->publicPayload($p, $goal);
    expect($payload['raised'])->toBe(100)
        ->and($payload['pct'])->toBe(100);
});

it('encerrar a meta zera a ativa', function () {
    $p = chatPerformer();
    $svc = app(TipGoalService::class);
    $svc->set($p, ['title' => 'X', 'target' => 100]);

    $svc->end($p);

    expect($svc->currentFor($p))->toBeNull()
        ->and($svc->publicPayload($p))->toBeNull();
});

it('o payload é agregado — nunca id/lista de quem deu, nem contagem', function () {
    $p = chatPerformer();
    $member = chatMember(1000);
    $svc = app(TipGoalService::class);
    $goal = $svc->set($p, ['title' => 'Setup', 'target' => 200]);
    tgTip($member, $p, 40);

    $payload = $svc->publicPayload($p, $goal);

    // Só as 4 chaves agregadas; nada de member/consumer/contagem.
    expect(array_keys($payload))->toBe(['title', 'target', 'raised', 'pct']);
    expect(json_encode($payload))->not->toContain('"'.$member->id.'"');
});

// ─── Só a performer dona gerencia ──────────────────────────────────────────────

it('só a performer ativa cria a própria meta pela rota', function () {
    $p = chatPerformer();

    $this->actingAs($p->user)
        ->post(route('performer.tip-goal.save'), ['title' => 'Setup', 'target' => 300])
        ->assertRedirect();

    expect(app(TipGoalService::class)->currentFor($p)?->title)->toBe('Setup');

    // Consumidor não passa no gate performer-active.
    $consumer = chatMember(0);
    $this->actingAs($consumer)
        ->post(route('performer.tip-goal.save'), ['title' => 'Hack', 'target' => 300])
        ->assertForbidden();
});

it('rejeita alvo abaixo do piso', function () {
    $p = chatPerformer();

    $this->actingAs($p->user)
        ->from(route('performer.profile.edit'))
        ->post(route('performer.tip-goal.save'), ['title' => 'Setup', 'target' => 1])
        ->assertSessionHasErrors('target');
});
