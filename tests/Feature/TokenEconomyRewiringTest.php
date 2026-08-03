<?php

use App\Models\Circle;
use App\Models\Subscription;
use App\Models\TokenPackage;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TokenCreditPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sprint 14 PR #131 — o código vivo passa a respeitar as invariantes M.13 já
 * mergeadas no PR #130. Aqui ficam as travas do REWIRING: sincronia circle↔config
 * e a autoridade de cobrança do desconto. O split de gorjeta (80% por evento) é
 * coberto pelos testes de gorjeta atualizados (TipPhase6Test/TipWebTest).
 */

// ─── Sincronia circle ↔ config (M.13.3 / M.13.4) ─────────────────────────────

it('mantem os circles em sincronia com a config M.13 (franquia e desconto)', function () {
    $franchises = config('monetization.franchises_by_tier');
    $discounts = config('monetization.discounts_by_tier');

    expect($franchises)->toHaveCount(5);

    foreach ($franchises as $slug => $franchise) {
        $circle = Circle::where('slug', $slug)->first();
        expect($circle)->not->toBeNull("circle {$slug}")
            ->and($circle->monthly_tokens)->toBe($franchise, "franquia {$slug}")
            ->and($circle->discount_pct)->toBe($discounts[$slug], "desconto {$slug}");
    }
});

// ─── Desconto na cobrança vem da CONFIG, não de circles.discount_pct ──────────

it('cobra o desconto da config M.13.3, nao de circles.discount_pct', function () {
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    Subscription::factory()->for($user)->circle('prestige')->create();
    $user->refresh();

    // Diverge o espelho de exibição de propósito: a AUTORIDADE de cobrança é a
    // config (prestige = 15%), então a cobrança ignora este 99.
    Circle::where('slug', 'prestige')->update(['discount_pct' => 99]);

    $package = TokenPackage::create([
        'slug' => 'rw-pack', 'name' => 'RW', 'tokens' => 300,
        'price_cents' => 1000, 'active' => true, 'sort_order' => 1,
    ]);

    $payment = app(PaymentService::class)->createPayment($user, $package);

    expect($payment->amount_cents)->toBe(850)   // 1000 − 15% (config), não −99%
        ->and($payment->tokens)->toBe(300);     // tokens nunca recebem desconto
});

it('purchaseDiscountPct le a config por tier e 0 sem assinatura', function () {
    $policy = app(TokenCreditPolicy::class);

    $none = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    expect($policy->purchaseDiscountPct($none))->toBe(0);

    foreach (['explorador' => 10, 'insider' => 10, 'prestige' => 15, 'black' => 20, 'founders_circle' => 25] as $slug => $pct) {
        $u = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
        Subscription::factory()->for($u)->circle($slug)->create();
        expect($policy->purchaseDiscountPct($u->refresh()))->toBe($pct, $slug);
    }
});
