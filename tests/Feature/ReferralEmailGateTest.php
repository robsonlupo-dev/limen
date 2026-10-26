<?php

use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Trava de E-MAIL CONFIRMADO no crédito do bônus de indicação
 * (feat/signup-email-gate-and-whatsapp-optin). O bônus só é creditado quando o
 * INDICADO confirmou o e-mail; sem confirmação, o sweep ADIA (nunca estorna), e o
 * crédito acontece assim que a confirmação chega.
 */
beforeEach(function () {
    config()->set('referral.enabled', true);
    config()->set('referral.hold_days', 14);
    config()->set('referral.rewards', [
        'member_purchase' => ['referrer' => 10, 'referred' => 5],
        'performer_first_earning' => ['referrer' => 40, 'referred' => 25],
    ]);
    $this->svc = app(ReferralService::class);
});

function gateMember(bool $verified): User
{
    $f = User::factory();
    if (! $verified) {
        $f = $f->unverified();
    }

    return $f->create()->refresh();
}

function gateConfirmedPayment(User $member): void
{
    \App\Models\Payment::create([
        'user_id' => $member->id,
        'token_package_id' => null,
        'provider' => 'asaas',
        'provider_charge_id' => 'pay_gate_'.uniqid(),
        'method' => 'pix',
        'amount_cents' => 4990,
        'tokens' => 50,
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
}

it('ADIA o crédito enquanto o indicado não confirmou o e-mail', function () {
    $referrer = gateMember(true);
    $referred = gateMember(false); // indicado com e-mail NÃO confirmado

    $this->svc->ensureCode($referrer);
    $this->svc->attributeAtSignup($referred, $referrer->referral_code, 'code');
    gateConfirmedPayment($referred);
    $this->svc->onReferredMemberPurchase($referred);

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    expect($referral->status)->toBe('qualified');

    $this->travel(15)->days();
    $result = $this->svc->processHolds();

    // Nada creditado: adiado, indicação segue 'qualified', recompensas ainda pending.
    expect($result['deferred'])->toBe(1)
        ->and($result['rewarded'])->toBe(0)
        ->and(app(TokenService::class)->balance($referrer))->toBe(0)
        ->and(app(TokenService::class)->balance($referred))->toBe(0)
        ->and($referral->fresh()->status)->toBe('qualified')
        ->and($referral->rewards()->where('status', 'pending')->count())->toBe(2);
});

it('credita os dois lados assim que o indicado confirma o e-mail', function () {
    $referrer = gateMember(true);
    $referred = gateMember(false);

    $this->svc->ensureCode($referrer);
    $this->svc->attributeAtSignup($referred, $referrer->referral_code, 'code');
    gateConfirmedPayment($referred);
    $this->svc->onReferredMemberPurchase($referred);

    $this->travel(15)->days();
    $this->svc->processHolds(); // adia (e-mail não confirmado)
    expect(app(TokenService::class)->balance($referrer))->toBe(0);

    // O indicado confirma o e-mail; o próximo sweep credita.
    $referred->forceFill(['email_verified_at' => now()])->save();
    $result = $this->svc->processHolds();

    expect($result['rewarded'])->toBe(1)
        ->and(app(TokenService::class)->balance($referrer))->toBe(10)
        ->and(app(TokenService::class)->balance($referred))->toBe(5)
        ->and(Referral::where('referred_user_id', $referred->id)->first()->status)->toBe('rewarded');
});
