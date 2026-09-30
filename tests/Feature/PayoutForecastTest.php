<?php

use App\Models\Payout;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Previsão de saque (Onda 4) — leitura que reflete EXATAMENTE as regras do sweep mensal:
 * sacável em R$ (floor R$0,60/token), próximo saque no dia 1, e o que falta pro automático
 * (mínimo de tokens, KYC, chave PIX de um saque anterior). Prefixo `pf`.
 */
function pfPerformer(bool $verified = true, string $status = 'active'): User
{
    $user = User::factory()->create(['role' => 'performer', 'status' => $status]);
    $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => $verified,
    ]);

    return $user->refresh();
}

function pfEarn(User $user, int $tokens): void
{
    app(TokenService::class)->credit($user, $tokens, 'tip_credit'); // ganho sacável
}

function pfKeyOnFile(User $user): Payout
{
    return Payout::create([
        'performer_id' => $user->id,
        'tokens' => 100,
        'amount_brl' => '60.00',
        'pix_key' => 'reuse@example.com',
        'pix_key_type' => 'email',
        'status' => 'paid',
        'requested_at' => now()->subMonthsNoOverflow(2),
    ]);
}

function pfForecast(User $user): array
{
    return app(PayoutService::class)->forecast($user);
}

it('abaixo do mínimo: não entra no automático e diz quanto falta', function () {
    $user = pfPerformer();
    pfEarn($user, 50); // mínimo é 100

    $f = pfForecast($user);

    expect($f['reaches_minimum'])->toBeFalse()
        ->and($f['tokens_to_minimum'])->toBe(50)
        ->and($f['withdrawable_centavos'])->toBe(3000)   // 50 × 60
        ->and($f['auto_eligible'])->toBeFalse()
        ->and($f['next_auto_estimate_centavos'])->toBe(0);
});

it('acima do mínimo, verificada e com chave: automático elegível com estimativa', function () {
    $user = pfPerformer();
    pfEarn($user, 200);
    pfKeyOnFile($user);

    $f = pfForecast($user);

    expect($f['reaches_minimum'])->toBeTrue()
        ->and($f['kyc_ok'])->toBeTrue()
        ->and($f['has_payout_key'])->toBeTrue()
        ->and($f['auto_eligible'])->toBeTrue()
        ->and($f['withdrawable_centavos'])->toBe(12000)          // 200 × 60
        ->and($f['next_auto_estimate_centavos'])->toBe(12000);
});

it('acima do mínimo mas sem saque anterior: pede o 1º saque manual', function () {
    $user = pfPerformer();
    pfEarn($user, 200); // sem pfKeyOnFile

    $f = pfForecast($user);

    expect($f['reaches_minimum'])->toBeTrue()
        ->and($f['has_payout_key'])->toBeFalse()
        ->and($f['auto_eligible'])->toBeFalse()
        ->and($f['next_auto_estimate_centavos'])->toBe(0);
});

it('sem KYC não entra no automático mesmo com saldo', function () {
    $user = pfPerformer(verified: false);
    pfEarn($user, 300);
    pfKeyOnFile($user);

    $f = pfForecast($user);

    expect($f['kyc_ok'])->toBeFalse()
        ->and($f['auto_eligible'])->toBeFalse();
});

it('o próximo saque automático é o dia 1 do próximo mês', function () {
    $user = pfPerformer();
    pfEarn($user, 200);

    $at = Carbon::parse(pfForecast($user)['next_auto_payout_at']);
    $expected = now('America/Sao_Paulo')->startOfMonth()->addMonthNoOverflow();

    expect($at->day)->toBe(1)
        ->and($at->format('Y-m'))->toBe($expected->format('Y-m'));
});
