<?php

use App\Models\PerformerProfile;
use App\Models\User;

/**
 * Correções da rodada de UAT de 16/09 (fix/uat-round-polish). Itens de layout/leitura
 * testados por FONTE (o projeto não tem Vitest — mesma disciplina do PanicButton/UAT);
 * a presença no card e o rótulo do ledger têm, além disso, teste de servidor.
 */

function uatMember(): User
{
    return User::factory()->create([
        'role' => 'consumer', 'status' => 'active',
        'email_verified_at' => now()->subDays(30), 'created_at' => now()->subDays(30),
    ]);
}

function uatPerformer(bool $online, bool $appearOffline = false): PerformerProfile
{
    $user = User::factory()->create([
        'role' => 'performer', 'status' => 'active',
        // Atividade recente = "online" (dentro da janela de 10min).
        'last_active_at' => $online ? now() : now()->subHours(2),
    ]);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Bella '.\Illuminate\Support\Str::random(5),
        'slug' => 'bella-'.strtolower(\Illuminate\Support\Str::random(6)),
        'category' => 'mulheres', 'is_verified' => true,
        'rate_public' => 60, 'rate_private' => 120, 'rate_camera' => 20,
    ]);
    $profile->forceFill(['appear_offline' => $appearOffline])->save();

    return $profile->fresh();
}

// ─── Item 1: nenhum entry_type cru vaza (frontend usava mapa local incompleto) ──

it('a carteira do membro (Index) usa o rótulo do servidor, nunca um mapa local com fallback ao tipo cru', function () {
    $src = file_get_contents(resource_path('js/Pages/Consumer/Wallet/Index.vue'));

    // Usa o label PRONTO do servidor (LedgerEntryLabel cobre o enum inteiro)…
    expect($src)->toContain('entry.label')
        // …e NÃO tem mais o mapa local nem o fallback ao entry_type cru, que era
        // o que vazava "spend_call" (tipos da chamada entraram depois do #202).
        ->and($src)->not->toContain('entryTypeLabels')
        ->and($src)->not->toContain('entryLabel(entry.entry_type)');
});

// (O teste de cobertura do enum — todo entry_type tem rótulo — vive em
// LedgerEntryLabelTest e lê o enum REAL do information_schema.)

// ─── Item 2: UMA formatação de token (helper único, pt-BR vírgula) ──────────────

it('existe um helper único de formatação de token e as telas de saldo o usam', function () {
    expect(file_exists(resource_path('js/lib/tokens.js')))->toBeTrue();

    foreach ([
        'js/Pages/Performer/Earnings/Index.vue',
        'js/Pages/Performer/Payouts/Index.vue',
        'js/Pages/Performer/Dashboard.vue',
        'js/Pages/Consumer/Wallet/Index.vue',
    ] as $page) {
        expect(file_get_contents(resource_path($page)))
            ->toContain("import { formatTokens } from '@/lib/tokens'");
    }

    // O replace ad-hoc que dava "300,4000" no extrato saiu.
    expect(file_get_contents(resource_path('js/Pages/Performer/Earnings/Index.vue')))
        ->not->toContain("String(value).replace('.', ',')");
});

// ─── Item 6: ícone de interface é SVG, nunca emoji ──────────────────────────────

it('os botões de chamada usam ícone SVG, não emoji de câmera/calendário', function () {
    $call = file_get_contents(resource_path('js/Components/CallRequest.vue'));
    $sched = file_get_contents(resource_path('js/Components/ScheduleCallModal.vue'));
    $coming = file_get_contents(resource_path('js/Components/ComingSoon.vue'));

    expect($call)->toContain('<svg')->and($call)->not->toContain('📹');
    expect($sched)->toContain('<svg')->and($sched)->not->toContain('🗓');
    expect($coming)->toContain('<svg')->and($coming)->not->toContain('📹')->and($coming)->not->toContain('📞');
});

// ─── Item 8: presença "Online agora" no card, respeitando o opt-out ─────────────

it('o card do catálogo mostra a presença online (deriva de is_available)', function () {
    $src = file_get_contents(resource_path('js/Components/PerformerCard.vue'));
    expect($src)->toContain('performer.is_available')->toContain('Online agora');
});

it('is_available reflete a presença E o opt-out appear_offline no payload do catálogo', function () {
    $member = uatMember();

    // Online e sem opt-out → disponível (o card mostra "Online agora").
    $online = uatPerformer(online: true, appearOffline: false);
    $this->actingAs($member)->get(route('catalog.show', $online->slug))
        ->assertOk()->assertInertia(fn ($p) => $p->where('performer.is_available', true));

    // Online MAS com opt-out (aparecer offline) → NÃO disponível (respeita o opt-out).
    $hidden = uatPerformer(online: true, appearOffline: true);
    $this->actingAs($member)->get(route('catalog.show', $hidden->slug))
        ->assertOk()->assertInertia(fn ($p) => $p->where('performer.is_available', false));

    // Sem atividade recente → não disponível.
    $offline = uatPerformer(online: false, appearOffline: false);
    $this->actingAs($member)->get(route('catalog.show', $offline->slug))
        ->assertOk()->assertInertia(fn ($p) => $p->where('performer.is_available', false));
});

// ─── Item 10: card de membro compacto (sem o vazio 3:4) ─────────────────────────

it('o card de membro é compacto (não mais o retrato 3:4 com silhueta boiando)', function () {
    $src = file_get_contents(resource_path('js/Components/MemberCard.vue'));
    expect($src)->not->toContain('aspect-[3/4]')          // acabou o card alto e vazio
        ->and($src)->toContain('fan_alias_label')          // alias segue
        ->and($src)->toContain("emit('view')");            // e abre o perfil
});

// ─── Item 12: redação dos links de histórico ────────────────────────────────────

it('os links de histórico têm a redação específica de cada tela', function () {
    expect(file_get_contents(resource_path('js/Pages/Performer/Payouts/Index.vue')))
        ->toContain('Ver histórico de saques');
    expect(file_get_contents(resource_path('js/Pages/Consumer/Wallet/Index.vue')))
        ->toContain('Ver histórico de gastos');
});
