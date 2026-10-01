<?php

use App\Models\FanclubMembership;
use App\Models\FanclubSettings;
use App\Models\PerformerContent;
use App\Services\ContentVisibilityService;
use App\Services\FanclubService;
use App\Services\PerformerContentService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Fã-Clube — UI da performer (Onda 4, fork da assinatura). Editor (abrir/preços), publicar
 * no set, e o sinal de baleia (roster anônimo). Eixos: (1) autorização de dono; (2) config
 * persistida com a trava público≥VIP; (3) escopo do set (não vaza na vitrine/painel); (4)
 * roster anônimo com piso + faixas. Prefixo `fcp`.
 */
function fcpSvc(): FanclubService
{
    return app(FanclubService::class);
}

function fcpExpire(FanclubMembership $m): void
{
    $m->forceFill(['current_period_start' => now()->subDays(31), 'current_period_end' => now()->subMinute()])->save();
}

// ─── Autorização + settings via HTTP ───────────────────────────────────────────

it('a performer abre o clube e salva os preços', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->post(route('performer.fanclub.settings'), [
            'is_open' => true,
            'price_public_tokens' => 50,
            'vip_enabled' => true,
            'price_vip_tokens' => 30,
        ])
        ->assertRedirect();

    $s = FanclubSettings::where('performer_profile_id', $performer->id)->first();
    expect($s)->not->toBeNull()
        ->and($s->is_open)->toBeTrue()
        ->and($s->price_public_tokens)->toBe(50)
        ->and($s->vip_enabled)->toBeTrue()
        ->and($s->price_vip_tokens)->toBe(30);
});

it('o consumidor não acessa o painel nem salva o clube', function () {
    $consumer = chatMember(0);

    $this->actingAs($consumer)
        ->post(route('performer.fanclub.settings'), ['is_open' => true, 'price_public_tokens' => 50, 'vip_enabled' => false])
        ->assertStatus(403);

    expect(FanclubSettings::count())->toBe(0);
});

it('VIP maior que o público é recusado e não abre o clube', function () {
    $performer = chatPerformer();

    $this->actingAs($performer->user)
        ->from(route('performer.fanclub'))
        ->post(route('performer.fanclub.settings'), [
            'is_open' => true, 'price_public_tokens' => 50, 'vip_enabled' => true, 'price_vip_tokens' => 80,
        ])
        ->assertRedirect(route('performer.fanclub'))
        ->assertSessionHasErrors('fanclub');

    expect(FanclubSettings::where('performer_profile_id', $performer->id)->first()?->is_open)->not->toBeTrue();
});

// ─── Publicar no set + escopo ──────────────────────────────────────────────────

it('publica no set; a peça fica no fã-clube e NÃO vaza na vitrine nem no painel', function () {
    $performer = chatPerformer();
    $member = chatMember(300);
    fcpSvc()->saveSettings($performer, true, 50);

    $piece = app(PerformerContentService::class)->publishToFanclub(
        $performer,
        UploadedFile::fake()->image('set.jpg', 640, 480),
    );

    expect($piece->fanclub)->toBeTrue()
        ->and($piece->price_tokens)->toBe(0)
        ->and($piece->access_level)->toBe(PerformerContent::LEVEL_EXCLUSIVE);

    // No painel do fã-clube sim; na vitrine pública e no painel de conteúdo, não.
    $set = app(PerformerContentService::class)->fanclubForOwner($performer);
    expect($set->pluck('id'))->toContain($piece->id);

    $gallery = app(ContentVisibilityService::class)->galleryFor($member, $performer);
    expect(collect($gallery)->pluck('id'))->not->toContain($piece->id);

    $panel = app(PerformerContentService::class)->forOwner($performer);
    expect($panel->pluck('id'))->not->toContain($piece->id);
});

// ─── Roster: piso de anonimato + faixas de baleia ──────────────────────────────

it('abaixo de 5 assinantes o roster não lista ninguém', function () {
    $performer = chatPerformer();
    fcpSvc()->saveSettings($performer, true, 50);

    foreach (range(1, 4) as $i) {
        fcpSvc()->subscribe(chatMember(50), $performer);
    }

    $roster = fcpSvc()->rosterFor($performer);
    expect($roster['below_floor'])->toBeTrue()
        ->and($roster['supporters'])->toBe([]);
});

it('com 5+ assinantes o roster lista por FanAlias + tier + faixa, sem id/nome', function () {
    $performer = chatPerformer();
    fcpSvc()->saveSettings($performer, true, 50);

    foreach (range(1, 5) as $i) {
        fcpSvc()->subscribe(chatMember(50), $performer);
    }

    $roster = fcpSvc()->rosterFor($performer);
    expect($roster['below_floor'])->toBeFalse()
        ->and($roster['supporters'])->toHaveCount(5);

    foreach ($roster['supporters'] as $s) {
        expect($s)->toHaveKeys(['alias', 'tier', 'band'])
            ->and($s)->not->toHaveKey('member_id')
            ->and($s)->not->toHaveKey('id')
            ->and($s['alias'])->toContain('Fã #')
            ->and($s['tier'])->toBe('Membro')   // free member
            ->and($s['band'])->toBe('novo');    // 1 cobrança, abaixo do alto
    }
});

it('faixas de baleia: recorrente com 2 cobranças, alto quando passa do limite', function () {
    $performer = chatPerformer();
    fcpSvc()->saveSettings($performer, true, 50);

    // 5 assinantes (acima do piso). O primeiro vai renovar (2 cobranças).
    $members = collect(range(1, 5))->map(fn () => chatMember(50));
    $members->each(fn ($m) => fcpSvc()->subscribe($m, $performer));

    // Renova o primeiro: +50 de saldo, vence o ciclo, roda o cron → 2 cobranças.
    $whale = $members->first();
    app(\App\Services\TokenService::class)->credit($whale, 50, 'purchase');
    fcpExpire(FanclubMembership::where('member_id', $whale->id)->first());
    fcpSvc()->processRenewals();

    // alto_tokens alto: o de 2 cobranças (total 100) é "recorrente", os demais "novo".
    config(['fanclub.whale.alto_tokens' => 100000, 'fanclub.whale.recorrente_charges' => 2]);
    $roster = fcpSvc()->rosterFor($performer);
    $whaleAlias = FanAlias::label($performer->id, $whale->id);
    $whaleRow = collect($roster['supporters'])->firstWhere('alias', $whaleAlias);
    expect($whaleRow['band'])->toBe('recorrente');

    // Baixando o limite de "alto" para 40, todo mundo (≥50 acumulado) vira "alto".
    config(['fanclub.whale.alto_tokens' => 40]);
    $roster2 = fcpSvc()->rosterFor($performer);
    expect(collect($roster2['supporters'])->every(fn ($s) => $s['band'] === 'alto'))->toBeTrue();
});
