<?php

use App\Models\User;
use App\Services\DocumentAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * UX do picker de agendamento (feat/scheduled-call-v1, round de UX). O membro não
 * conseguia agendar "para já" e só via o 422 cru "fora da janela". O modal passou a
 * (a) receber a JANELA do servidor (min_lead_minutes/max_lead_days/fuso) para mostrar
 * o intervalo permitido e abrir num horário válido, e (b) escolher DIA e HORA em dois
 * campos com um OK. Aqui garantimos o contrato: o controller passa `scheduleWindow`
 * com os valores de config, e o componente tem os dois campos + o guarda de janela.
 * Helpers com prefixo `scp` para não colidir com os globais do ScheduledCallTest.
 */
function scpPerformer(int $price = 10): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(8)),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
    $profile->forceFill(['call_price_per_minute' => $price])->save();
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

function scpMember(): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    app(DocumentAcceptanceService::class)->acceptAll($user, Request::create('/', 'POST'));

    return $user->fresh();
}

it('catalog.show passa scheduleWindow com os valores de config/scheduled_call.php', function () {
    config([
        'scheduled_call.min_lead_minutes' => 45,
        'scheduled_call.max_lead_days' => 30,
        'scheduled_call.display_timezone' => 'America/Sao_Paulo',
    ]);
    $perf = scpPerformer();
    $member = scpMember();

    $this->actingAs($member)->get(route('catalog.show', $perf->performerProfile->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('scheduleWindow.minLeadMinutes', 45)
            ->where('scheduleWindow.maxLeadDays', 30)
            ->where('scheduleWindow.timezone', 'America/Sao_Paulo')
        );
});

it('o scheduleWindow acompanha a config (min_lead customizado)', function () {
    config(['scheduled_call.min_lead_minutes' => 90]);
    $perf = scpPerformer();
    $member = scpMember();

    $this->actingAs($member)->get(route('catalog.show', $perf->performerProfile->slug))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('scheduleWindow.minLeadMinutes', 90));
});

it('o modal escolhe DIA e HORA em campos separados com um OK', function () {
    $src = file_get_contents(resource_path('js/Components/ScheduleCallModal.vue'));

    expect($src)->toContain('type="date"');
    expect($src)->toContain('type="time"');
    // Recebe a janela do servidor e deriva os limites dela.
    expect($src)->toContain('scheduleWindow');
    expect($src)->toContain('minLeadMinutes');
    expect($src)->toContain('maxLeadDays');
    // Guarda de janela no cliente (não deixa só o 422 cru falar).
    expect($src)->toContain('tooSoon');
    expect($src)->toContain('tooFar');
    // Folga sobre o corte do servidor (now+min_lead ao segundo): o horário oferecido
    // precisa ser reservável de verdade, senão volta 422 "fora da janela" por corrida
    // de relógio. E revalida contra o relógio ATUAL no envio (cobre demora no modal).
    expect($src)->toContain('SAFETY_MS');
    expect($src)->toContain('earliestBookable');
    expect($src)->toContain('const liveEarliest = earliestBookable()');
    // Mostra o intervalo permitido e a prévia do que foi escolhido.
    expect($src)->toContain('earliestLabel');
    expect($src)->toContain('chosenLabel');
    // Segue mandando scheduled_at para a mesma rota.
    expect($src)->toContain("route('reservations.store', props.performerProfileId)");
    expect($src)->toContain('scheduled_at');
    // Ícone de UI é SVG, nunca emoji (regra do PO).
    expect($src)->toContain('<svg')->and($src)->not->toContain('🗓');
});
