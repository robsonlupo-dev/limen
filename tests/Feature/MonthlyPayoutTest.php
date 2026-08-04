<?php

use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\PayoutService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Sweep mensal do payout (PR #134, M.10/M.13.5): paga os ganhos devidos do mês que
 * fechou, R$0,60/token fixo. Helpers locais (mp*) para o arquivo rodar isolado.
 * Revisão de segurança do plano e do código rodada (regra do CLAUDE.md p/ payout).
 */
function mpPerformer(string $status = 'active', bool $verified = true): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);
    $user->performerProfile()->create([
        'stage_name' => 'Ana '.Str::random(6),
        'slug' => 'ana-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $verified,
    ]);

    return $user->refresh();
}

function mpEarn(User $user, int $tokens, string $type = 'tip_credit'): void
{
    app(TokenService::class)->credit($user, $tokens, $type);
}

/** Um saque anterior BEM-SUCEDIDO, só para ter chave PIX no arquivo (sem reserva). */
function mpKeyOnFile(User $user, string $status = 'paid', string $key = 'reuse@example.com'): Payout
{
    return Payout::create([
        'performer_id' => $user->id,
        'tokens' => 100,
        'amount_brl' => '60.00',
        'pix_key' => $key,
        'pix_key_type' => 'email',
        'status' => $status,
        'requested_at' => now()->subMonthsNoOverflow(2),
    ]);
}

function mpSweep(): array
{
    return app(PayoutService::class)->sweepMonthlyPayouts();
}

function mpBalance(User $user): int
{
    return app(TokenService::class)->balance($user);
}

/** O payout mensal (o que tem período preenchido), distinto de fixtures on-demand. */
function mpMonthlyPayout(User $user): ?Payout
{
    return Payout::where('performer_id', $user->id)->whereNotNull('period_year')->latest('id')->first();
}

// ─── Cálculo R$0,60/token (M.13.5) ───────────────────────────────────────────

it('paga a franquia de ganhos a R$0,60/token, nao split_pct', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user);

    $stats = mpSweep();

    expect($stats['created'])->toBe(1)
        ->and($stats['tokens'])->toBe(500);

    $payout = mpMonthlyPayout($user);
    expect($payout->tokens)->toBe(500)
        ->and((float) $payout->amount_brl)->toBe(300.00) // 500 × 0,60
        ->and($payout->period_year)->not->toBeNull()
        ->and($payout->period_month)->not->toBeNull();
});

it('soma creditos de multiplos tipos de ganho', function () {
    $user = mpPerformer();
    mpEarn($user, 300, 'tip_credit');
    mpEarn($user, 250, 'chat_access_credit');
    mpKeyOnFile($user);

    mpSweep();

    $payout = mpMonthlyPayout($user);
    expect($payout->tokens)->toBe(550)
        ->and((float) $payout->amount_brl)->toBe(330.00);
});

// ─── Mínimo de 100 (M.10) ────────────────────────────────────────────────────

it('nao processa abaixo de 100 tokens', function () {
    $user = mpPerformer();
    mpEarn($user, 50);
    mpKeyOnFile($user);

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped_below_min'])->toBe(1)
        ->and(mpMonthlyPayout($user))->toBeNull();
});

it('nao processa performer sem creditos', function () {
    $user = mpPerformer();
    mpKeyOnFile($user);

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and(mpMonthlyPayout($user))->toBeNull();
});

// ─── Só GANHOS são sacáveis — nunca purchase/bonus/grant (B1, anti-leak) ─────

it('paga SO os ganhos, nunca purchase/bonus/refund/subscription_grant (B1)', function () {
    $user = mpPerformer();
    mpEarn($user, 120, 'tip_credit'); // único ganho — o resto é saldo não-sacável
    mpEarn($user, 3000, 'bonus');
    mpEarn($user, 1000, 'refund');
    mpEarn($user, 500, 'staging_seed_backfill');
    app(TokenService::class)->credit($user, 2000, 'subscription_grant');
    mpKeyOnFile($user);

    $stats = mpSweep();

    // Devido = 120 (só o tip_credit), jamais os 6.500 de saldo não-ganho.
    expect($stats['created'])->toBe(1)
        ->and($stats['tokens'])->toBe(120)
        ->and(mpMonthlyPayout($user)->tokens)->toBe(120);
});

it('performer so com credito nao-ganho nao vira pagamento nenhum', function () {
    $user = mpPerformer();
    mpEarn($user, 3000, 'bonus');
    app(TokenService::class)->credit($user, 2000, 'subscription_grant');
    mpKeyOnFile($user);

    $stats = mpSweep();

    // Sem nenhum crédito de ganho, nem candidata é: zero pagamento, zero leak.
    expect($stats['created'])->toBe(0)
        ->and(Payout::whereNotNull('period_year')->count())->toBe(0);
});

// ─── Idempotência por mês ────────────────────────────────────────────────────

it('nao processa o mesmo mes duas vezes', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user);

    $first = mpSweep();
    $second = mpSweep();

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and(Payout::where('performer_id', $user->id)->whereNotNull('period_year')->count())->toBe(1);
});

// ─── Um saque PAGO reduz "devido" para sempre — não re-paga (B2) ─────────────

it('um sweep pago reduz o devido para sempre, sem re-pagar no mes seguinte', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    $user = mpPerformer();
    mpEarn($user, 1000);
    mpKeyOnFile($user);

    $first = mpSweep(); // período ago/2026: paga 1000
    expect($first['created'])->toBe(1)
        ->and($first['tokens'])->toBe(1000);

    // Mês seguinte, sem novos ganhos: nada devido (o reserve do sweep pago fica).
    Carbon::setTestNow('2026-10-02 03:00:00');
    $second = mpSweep(); // período set/2026

    expect($second['created'])->toBe(0)
        ->and($second['skipped_below_min'])->toBe(1)
        ->and(Payout::where('performer_id', $user->id)->whereNotNull('period_year')->count())->toBe(1);

    Carbon::setTestNow();
});

it('desconta um saque on-demand anterior do devido do sweep (sem pagamento dobrado)', function () {
    $user = mpPerformer();
    mpEarn($user, 1000);

    // On-demand tira 400 (cria a chave no arquivo e reserva 400).
    app(PayoutService::class)->requestPayout($user, 400, 'perf@example.com', 'email');

    $stats = mpSweep(); // devido agora = 1000 − 400 = 600

    expect($stats['created'])->toBe(1)
        ->and($stats['tokens'])->toBe(600)
        ->and(mpMonthlyPayout($user)->pix_key)->toBe('perf@example.com'); // chave reusada
});

// ─── Chave PIX: reuso e ausência ─────────────────────────────────────────────

it('reusa a chave PIX do ultimo saque bem-sucedido', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user, 'paid', 'minhachave@pix.com');

    mpSweep();

    expect(mpMonthlyPayout($user)->pix_key)->toBe('minhachave@pix.com');
});

it('pula performer sem saque anterior (sem chave PIX no arquivo)', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    // Sem mpKeyOnFile: nunca sacou.

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped_no_key'])->toBe(1)
        ->and(mpMonthlyPayout($user))->toBeNull();
});

it('NAO reusa a chave de um saque que FALHOU (S2)', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user, 'failed', 'chave-ruim@pix.com'); // só um failed no arquivo

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped_no_key'])->toBe(1);
});

// ─── Elegibilidade da conta (B4) ─────────────────────────────────────────────

it('NAO paga performer banida, mesmo com KYC e ganhos (B4)', function () {
    $user = mpPerformer('banned', true);
    mpEarn($user, 500);
    mpKeyOnFile($user);

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped_ineligible'])->toBe(1)
        ->and(mpMonthlyPayout($user))->toBeNull();
});

it('NAO paga performer sem KYC verificado', function () {
    $user = mpPerformer('active', false);
    mpEarn($user, 500);
    mpKeyOnFile($user);

    $stats = mpSweep();

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped_ineligible'])->toBe(1);
});

// ─── Caminhos de transferência (falha / ambíguo) no sweep ────────────────────

it('falha definitiva no transfer do sweep estorna os tokens (payout_reversal)', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextTransferFailure();

    mpSweep();

    $payout = mpMonthlyPayout($user);
    expect($payout->status)->toBe('failed')
        ->and(mpBalance($user))->toBe(500); // reserva estornada

    $reversal = TokenLedger::where('reference_type', 'payout')
        ->where('reference_id', $payout->id)
        ->where('entry_type', 'payout_reversal')
        ->first();
    expect($reversal->amount)->toBe(500);
});

it('resultado ambiguo no sweep NAO estorna, deixa em processing', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user);

    /** @var FakeAsaasClient $fake */
    $fake = app(AsaasClientInterface::class);
    $fake->forceNextTransferUnavailable();

    mpSweep();

    $payout = mpMonthlyPayout($user);
    expect($payout->status)->toBe('processing')
        ->and(mpBalance($user))->toBe(0) // reserva mantida
        ->and(TokenLedger::where('reference_type', 'payout')->where('reference_id', $payout->id)
            ->where('entry_type', 'payout_reversal')->exists())->toBeFalse();
});

// ─── Wiring do command ───────────────────────────────────────────────────────

it('roda pelo artisan e reporta a contagem', function () {
    $user = mpPerformer();
    mpEarn($user, 500);
    mpKeyOnFile($user);

    $this->artisan('payouts:process-monthly')
        ->expectsOutputToContain('created=1')
        ->assertExitCode(0);
});

// ─── Exposição no dashboard (R$0,60, valor sacável) ──────────────────────────

it('expoe payoutRatePerToken e withdrawableTokens no dashboard', function () {
    $user = mpPerformer();
    mpEarn($user, 500);

    $this->actingAs($user)
        ->get('/performer/payouts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Performer/Payouts/Index')
            ->where('payoutRatePerToken', 0.6)
            ->where('withdrawableTokens', 500)
            ->where('minTokens', 100)
        );
});
