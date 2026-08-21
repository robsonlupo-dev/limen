<?php

use App\Models\PerformerContent;
use App\Models\TokenLedger;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * feat/gift-split-and-tier-visibility: Premium virou COMPRA AVULSA (21/08/2026) —
 * qualquer membro compra pagando o preço cheio; o desconto do assinante fica na
 * compra de tokens (decisão do PO, Model A). Exclusivo/FC Only seguem travados por
 * tier. Bytes ORIGINAIS nunca são servidos a quem não pagou, em nível nenhum.
 *
 * Reusa os helpers de PermanentContentTest (pcPerformer/pcMember/pcPublish/pcUnlock/
 * pcVis/pcBalance).
 */

it('membro Free compra Premium avulso pagando o preço cheio; performer credita 80% exato', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);

    $free = pcMember(); // sem assinatura
    app(TokenService::class)->credit($free, 50, 'purchase');

    $unlock = pcUnlock($free, $content);

    expect($unlock->tokens_paid)->toBe(20)                    // preço CHEIO
        ->and(pcBalance($free))->toBe(30)                     // 50 − 20
        ->and(pcVis()->canView($free, $content))->toBeTrue(); // passou a ver

    $credit = TokenLedger::where('entry_type', 'content_credit')->latest('id')->first();
    expect($credit->amount)->toBe(16)                          // 80% de 20
        ->and((int) $credit->applied_rate)->toBe(80)
        ->and(pcBalance($profile->user))->toBe(16);
});

it('membro Free NAO consegue comprar Exclusivo (segue travado por tier) — 403, nada cobrado', function () {
    $profile = pcPerformer();
    $content = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);

    $free = pcMember();
    app(TokenService::class)->credit($free, 100, 'purchase');

    expect(pcVis()->canUnlock($free, $content))->toBeFalse();

    $this->actingAs($free)->postJson(route('content.unlock', $content->id))
        ->assertStatus(403);

    expect(pcBalance($free))->toBe(100)
        ->and(TokenLedger::where('entry_type', 'content_credit')->count())->toBe(0);
});

it('bytes originais NUNCA servidos a quem nao pagou — Premium/Exclusivo/FC dao 404', function () {
    $profile = pcPerformer();
    $premium = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $exclusive = pcPublish($profile, PerformerContent::LEVEL_EXCLUSIVE, 50);
    $fc = pcPublish($profile, PerformerContent::LEVEL_FC_ONLY, 80);

    $free = pcMember();
    app(TokenService::class)->credit($free, 100, 'purchase');

    // Sem desbloqueio: o serving 404 em TODOS os níveis (canView false → nunca os bytes).
    foreach ([$premium, $exclusive, $fc] as $c) {
        $this->actingAs($free)->get(route('content.image', $c->id))->assertNotFound();
    }

    // Depois de comprar o Premium, só ELE passa a servir; Exclusivo segue 404.
    pcUnlock($free, $premium);
    $this->actingAs($free)->get(route('content.image', $premium->id))->assertOk();
    $this->actingAs($free)->get(route('content.image', $exclusive->id))->assertNotFound();
});

it('a compra avulsa de Premium cobra o preço CHEIO de todos os tiers (desconto fica na compra de tokens)', function () {
    // Model A (decisão do PO 21/08/2026): o desconto por tier NÃO incide no
    // desbloqueio — segue só na compra de tokens. Free e Prestige pagam o MESMO
    // número de tokens pela mesma peça.
    $profile = pcPerformer();
    $pieceA = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);
    $pieceB = pcPublish($profile, PerformerContent::LEVEL_PREMIUM, 20);

    $free = pcMember();
    $prestige = pcMember('prestige');
    app(TokenService::class)->credit($free, 50, 'purchase');
    app(TokenService::class)->credit($prestige, 50, 'purchase');

    $uFree = pcUnlock($free, $pieceA);
    $uPrestige = pcUnlock($prestige, $pieceB);

    $policy = app(TokenCreditPolicy::class);

    expect($uFree->tokens_paid)->toBe(20)
        ->and($uPrestige->tokens_paid)->toBe(20)               // MESMO preço em tokens
        // O desconto por tier segue existindo — na COMPRA de tokens, não no gasto.
        ->and($policy->purchaseDiscountPct($prestige))->toBe(15)
        ->and($policy->purchaseDiscountPct($free))->toBe(0);
});
