<?php

use App\Models\Payment;
use App\Models\PerformerProfile;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('referral.enabled', true);
    config()->set('referral.hold_days', 14);
    config()->set('referral.rewards', [
        'member_purchase' => ['referrer' => 10, 'referred' => 5],
        'performer_first_earning' => ['referrer' => 40, 'referred' => 25],
    ]);
    $this->svc = app(ReferralService::class);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function refMember(array $attrs = []): User
{
    $user = User::factory()->create();
    // registration_ip_hash e nickname são guarded (fora do $fillable) — a factory os
    // descartaria; forceFill garante que o arrange do teste realmente os grave.
    if ($attrs !== []) {
        $user->forceFill($attrs)->save();
    }

    return $user->refresh();
}

function refPerformer(array $attrs = []): User
{
    $user = User::factory()->performer()->create();
    PerformerProfile::factory()->for($user)->create(['is_verified' => true]);
    if ($attrs !== []) {
        $user->forceFill($attrs)->save();
    }

    return $user->refresh();
}

/** Vincula $referred a $referrer como se tivesse vindo pelo código no cadastro. */
function refLink(ReferralService $svc, User $referrer, User $referred, string $via = 'code'): void
{
    $svc->ensureCode($referrer);
    $svc->attributeAtSignup($referred, $referrer->referral_code, $via);
}

function refConfirmedPayment(User $member): Payment
{
    return Payment::create([
        'user_id' => $member->id,
        'token_package_id' => null,
        'provider' => 'asaas',
        'provider_charge_id' => 'pay_test_'.uniqid(),
        'method' => 'pix',
        'amount_cents' => 4990,
        'tokens' => 50,
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
}

/** Dá à performer um ganho (tip_credit) pago por $payer, via o elo que EarningPayers lê. */
function refTipEarning(User $performer, User $payer): void
{
    $ts = app(TokenService::class);
    // consumer_ledger_id é FK NOT NULL — precisa de uma linha real do pagador.
    $payerLedger = $ts->credit($payer, 2, 'purchase');
    $perfLedger = $ts->credit($performer, '1.6000', 'tip_credit', null, null, 'Gorjeta recebida — Fã #0001');

    Tip::create([
        'consumer_id' => $payer->id,
        'performer_profile_id' => $performer->performerProfile->id,
        'amount' => 2,
        'performer_amount' => 2,
        'platform_amount' => 0,
        'idempotency_key' => 'tip_'.uniqid(),
        'consumer_ledger_id' => $payerLedger->id,
        'performer_ledger_id' => $perfLedger->id,
    ]);
}

// ── Atribuição ───────────────────────────────────────────────────────────────

it('vincula o indicado ao indicador pelo código e cria a indicação pendente', function () {
    $referrer = refMember();
    $referred = refMember();

    refLink($this->svc, $referrer, $referred);

    expect($referred->fresh()->referred_by_user_id)->toBe($referrer->id);

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe('pending')
        ->and($referral->referrer_user_id)->toBe($referrer->id)
        ->and($referral->referred_role_at_signup)->toBe('member');
});

it('o vínculo é imutável — uma segunda atribuição não o sobrescreve', function () {
    $first = refMember();
    $second = refMember();
    $referred = refMember();

    refLink($this->svc, $first, $referred);
    $this->svc->ensureCode($second);
    $this->svc->attributeAtSignup($referred, $second->referral_code, 'code');

    expect($referred->fresh()->referred_by_user_id)->toBe($first->id)
        ->and(Referral::where('referred_user_id', $referred->id)->count())->toBe(1);
});

it('não cria vínculo para código inexistente', function () {
    $referred = refMember();
    $this->svc->attributeAtSignup($referred, 'LM-XXXXX', 'code');

    expect($referred->fresh()->referred_by_user_id)->toBeNull()
        ->and(Referral::count())->toBe(0);
});

it('bloqueia auto-indicação por contas ligadas (mesmo IP de cadastro)', function () {
    $referrer = refPerformer(['registration_ip_hash' => 'same-hash']);
    $referred = refPerformer(['registration_ip_hash' => 'same-hash']);

    refLink($this->svc, $referrer, $referred);

    expect($referred->fresh()->referred_by_user_id)->toBeNull()
        ->and(Referral::count())->toBe(0);
});

it('não atribui quando o programa está desligado', function () {
    config()->set('referral.enabled', false);
    $referrer = refMember();
    $referred = refMember();

    refLink($this->svc, $referrer, $referred);

    expect($referred->fresh()->referred_by_user_id)->toBeNull();
});

// ── Código do usuário ────────────────────────────────────────────────────────

it('gera um código único e estável por usuário', function () {
    $u = refMember();
    $code = $this->svc->ensureCode($u);

    expect($code)->toStartWith('LM-')
        ->and($this->svc->ensureCode($u->fresh()))->toBe($code); // idempotente
});

// ── Conversão de membro (3.A) ────────────────────────────────────────────────

it('qualifica na 1ª compra do membro e credita os dois lados após o hold (não-sacável)', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    refConfirmedPayment($referred);

    $this->svc->onReferredMemberPurchase($referred);

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    expect($referral->status)->toBe('qualified')
        ->and($referral->rewards()->where('status', 'pending')->count())->toBe(2);

    // Antes do hold, nada é creditado.
    expect(app(TokenService::class)->balance($referrer))->toBe(0);

    $this->travel(15)->days();
    $result = $this->svc->processHolds();

    expect($result['rewarded'])->toBe(1)
        ->and(app(TokenService::class)->balance($referrer))->toBe(10)
        ->and(app(TokenService::class)->balance($referred))->toBe(5)
        ->and($referral->fresh()->status)->toBe('rewarded');

    // Não-sacável por construção: o entry_type NÃO está no allowlist de payout.
    expect(config('monetization.payout.earning_entry_types'))->not->toContain('referral_bonus');
});

it('não recredita se a linha de ledger do bônus já existe (idempotência a crash)', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    refConfirmedPayment($referred);
    $this->svc->onReferredMemberPurchase($referred);

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    $reward = $referral->rewards()->where('role', 'referrer')->first();

    // Simula o crash: o ledger foi creditado, mas o status do reward não subiu.
    $ledger = app(TokenService::class)->credit(
        $referrer, $reward->amount, 'referral_bonus', 'referral_reward', $reward->id, 'Bônus de indicação',
    );

    $this->travel(15)->days();
    $this->svc->processHolds();

    // Saldo = só os 10 já creditados (não 20), e o reward aponta para o ledger existente.
    expect(app(TokenService::class)->balance($referrer))->toBe(10)
        ->and($reward->fresh()->status)->toBe('rewarded')
        ->and($reward->fresh()->ledger_id)->toBe($ledger->id)
        ->and(TokenLedger::where('entry_type', 'referral_bonus')->where('reference_id', $reward->id)->count())->toBe(1);
});

it('clawback estorna o bônus já creditado e debita até o saldo disponível', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    refConfirmedPayment($referred);
    $this->svc->onReferredMemberPurchase($referred);
    $this->travel(15)->days();
    $this->svc->processHolds(); // credita 10 / 5

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    $this->svc->clawback($referral, 'chargeback');

    expect(app(TokenService::class)->balance($referrer))->toBe(0)
        ->and(app(TokenService::class)->balance($referred))->toBe(0)
        ->and($referral->fresh()->status)->toBe('clawed_back')
        ->and(ReferralReward::where('referral_id', $referral->id)->where('status', 'reversed')->count())->toBe(2);
});

it('é idempotente: reprocessar a compra não duplica recompensas', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    refConfirmedPayment($referred);

    $this->svc->onReferredMemberPurchase($referred);
    $this->svc->onReferredMemberPurchase($referred);

    expect(ReferralReward::count())->toBe(2);
});

it('não credita se a base foi estornada durante o hold (clawback)', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    $payment = refConfirmedPayment($referred);

    $this->svc->onReferredMemberPurchase($referred);

    // Estorno antes do fim do hold.
    $payment->update(['status' => 'refunded']);

    $this->travel(15)->days();
    $result = $this->svc->processHolds();

    expect($result['clawed_back'])->toBe(1)
        ->and(app(TokenService::class)->balance($referrer))->toBe(0)
        ->and(Referral::where('referred_user_id', $referred->id)->first()->status)->toBe('clawed_back');
});

it('adia a recompensa quando o beneficiário está no teto de acúmulo', function () {
    $referrer = refMember();
    $referred = refMember();
    refLink($this->svc, $referrer, $referred);
    refConfirmedPayment($referred);
    $this->svc->onReferredMemberPurchase($referred);

    // Coloca o indicador exatamente no teto (5000) — o bônus não cabe.
    app(TokenService::class)->credit($referrer, 5000, 'purchase');

    $this->travel(15)->days();
    $result = $this->svc->processHolds();

    expect($result['deferred'])->toBe(1)
        ->and(Referral::where('referred_user_id', $referred->id)->first()->status)->toBe('qualified');
});

// ── Conversão de performer (3.B) ─────────────────────────────────────────────

it('qualifica a performer com KYC + 1º ganho de um TERCEIRO e credita 40/25', function () {
    $referrer = refMember();
    $performer = refPerformer();
    refLink($this->svc, $referrer, $performer);

    // Parte 1: KYC aprovado (registra, não qualifica).
    $this->svc->onPerformerKycApproved($performer);
    expect(Referral::where('referred_user_id', $performer->id)->first()->kyc_approved_at)->not->toBeNull();

    // Parte 2: ganho de um terceiro genuíno.
    $thirdParty = refMember();
    refTipEarning($performer, $thirdParty);

    $result = $this->svc->processHolds();
    expect($result['qualified'])->toBe(1);

    $this->travel(15)->days();
    $this->svc->processHolds();

    // Indicador (membro): 0 + 40 = 40. Performer: 1,6 (tip) + 25 (bônus) = 26,6.
    expect(app(TokenService::class)->balance($referrer))->toBe(40)
        ->and(app(TokenService::class)->balance($performer))->toBe('26.6000')
        ->and(Referral::where('referred_user_id', $performer->id)->first()->status)->toBe('rewarded');
});

it('não qualifica a performer se o único ganho veio do próprio indicador', function () {
    $referrer = refMember();
    $performer = refPerformer();
    refLink($this->svc, $referrer, $performer);
    $this->svc->onPerformerKycApproved($performer);

    // O "ganho" foi pago pelo PRÓPRIO indicador — não conta como terceiro.
    refTipEarning($performer, $referrer);

    $result = $this->svc->processHolds();

    expect($result['qualified'])->toBe(0)
        ->and(Referral::where('referred_user_id', $performer->id)->first()->status)->toBe('pending');
});

it('não qualifica a performer com ganho de conta ligada ao indicador (mesmo IP)', function () {
    $referrer = refPerformer(['registration_ip_hash' => 'ring-hash']);
    $performer = refPerformer();
    // vincula manualmente (contas ligadas bloqueariam a atribuição automática, mas
    // aqui o pagador é uma TERCEIRA conta ligada ao indicador, não o indicado)
    $this->svc->ensureCode($referrer);
    $performer->forceFill(['referred_by_user_id' => $referrer->id])->save();
    Referral::create([
        'referrer_user_id' => $referrer->id,
        'referred_user_id' => $performer->id,
        'referred_role_at_signup' => 'performer',
        'status' => 'pending',
        'kyc_approved_at' => now(),
    ]);

    $linkedPayer = refMember(['registration_ip_hash' => 'ring-hash']);
    refTipEarning($performer, $linkedPayer);

    expect($this->svc->processHolds()['qualified'])->toBe(0);
});

// ── Privacidade dos rótulos (§7.3) ───────────────────────────────────────────

it('rotula a performer pelo stage_name e o membro sem apelido genericamente', function () {
    $performer = refPerformer();
    $performer->performerProfile->update(['stage_name' => 'Aurora']);
    expect($this->svc->displayLabelFor($performer->fresh()))->toBe('Aurora');

    $member = refMember(['nickname' => null]);
    expect($this->svc->displayLabelFor($member))->toBe('um membro que você indicou');

    $withNick = refMember(['nickname' => 'Rob']);
    expect($this->svc->displayLabelFor($withNick))->toBe('Rob');
});
