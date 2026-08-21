<?php

use App\Exceptions\CapExceededException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invariantes da economia de tokens — emenda M.13 (PR #130 do Sprint 14).
 *
 * Cada teste trava UMA invariante. A dona única é TokenCreditPolicy; os números
 * vêm de config/monetization.php. Revisão de segurança do plano rodada antes de
 * codar (regra do CLAUDE.md para ledger/pagamento).
 *
 * Helpers com prefixo `tee` — as funções do Pest são globais.
 */
function teePolicy(): TokenCreditPolicy
{
    return app(TokenCreditPolicy::class);
}

function teeMember(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function teeSubscribe(User $user, string $slug): void
{
    Subscription::factory()->for($user)->circle($slug)->create();
    $user->refresh();
}

/** Semeia saldo por um tipo que NÃO respeita teto (setup só de teste). */
function teeSeed(User $user, int $amount): void
{
    if ($amount > 0) {
        app(TokenService::class)->credit($user, $amount, 'adjustment', description: 'seed');
    }
}

function teeBalance(User $user): int
{
    return app(TokenService::class)->balance($user);
}

function teePending(User $user): int
{
    return (int) TokenWallet::where('user_id', $user->id)->value('pending_grant_tokens');
}

// ─── TETO É PROPRIEDADE DO MOVIMENTO, NÃO DA PESSOA (M.13.9) ──────────────────

it('deixa estorno passar acima do teto', function () {
    $u = teeMember();
    teeSeed($u, 4900);

    teePolicy()->credit($u, 240, 'refund');

    expect(teeBalance($u))->toBe(5140);
});

it('deixa adjustment e payout_reversal passarem acima do teto', function () {
    $u = teeMember();
    teeSeed($u, 4900);

    teePolicy()->credit($u, 240, 'adjustment');
    teePolicy()->credit($u, 100, 'payout_reversal');

    expect(teeBalance($u))->toBe(5240);
});

it('barra purchase e bonus acima do teto', function () {
    $u = teeMember();
    teeSeed($u, 4900);

    expect(fn () => teePolicy()->credit($u, 240, 'purchase'))->toThrow(CapExceededException::class);
    expect(fn () => teePolicy()->credit($u, 240, 'bonus'))->toThrow(CapExceededException::class);
    // Nada foi creditado pelos dois barrados.
    expect(teeBalance($u))->toBe(4900);
});

it('escalona o teto: FC recebe ate 8000, Black nao', function () {
    $fc = teeMember();
    teeSubscribe($fc, 'founders_circle');
    teePolicy()->credit($fc, 8000, 'purchase');            // 0 + 8000 == cap 8000, passa
    expect(teeBalance($fc))->toBe(8000)
        ->and(fn () => teePolicy()->credit($fc, 1, 'purchase'))->toThrow(CapExceededException::class);

    $black = teeMember();
    teeSubscribe($black, 'black');
    expect(fn () => teePolicy()->credit($black, 8000, 'purchase'))->toThrow(CapExceededException::class);
    teePolicy()->credit($black, 5000, 'purchase');         // 0 + 5000 == cap 5000, passa
    expect(teeBalance($black))->toBe(5000);
});

it('usa entry_type e nao role: tip_credit livre e subscription_grant capado na MESMA carteira de performer que assina', function () {
    // Performer com Círculo ativo (M.13.9: performer pode assinar).
    $perf = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    teeSubscribe($perf, 'black'); // cap 5000, franquia 1000
    teeSeed($perf, 4900);

    // tip_credit nunca respeita teto — passa livre.
    teePolicy()->credit($perf, 500, 'tip_credit');
    expect(teeBalance($perf))->toBe(5400);

    // subscription_grant na MESMA carteira respeita o teto: já acima do teto,
    // credita 0 e pendura. A chave é o entry_type, não o role.
    teePolicy()->credit($perf, 1000, 'subscription_grant');
    expect(teeBalance($perf))->toBe(5400)          // nada creditado
        ->and(teePending($perf))->toBe(1000);      // franquia inteira pendurada
});

// ─── FILA DE PENDÊNCIA (M.13.8) ──────────────────────────────────────────────

it('grant parcial credita o que cabe e pendura o excedente', function () {
    $u = teeMember();
    teeSubscribe($u, 'black');   // cap 5000, franquia 1000
    teeSeed($u, 4700);

    teePolicy()->credit($u, 1200, 'subscription_grant');

    expect(teeBalance($u))->toBe(5000)   // creditou 300 (room)
        ->and(teePending($u))->toBe(900); // pendurou 900
});

it('grant parcial com teto de FC (8000)', function () {
    $u = teeMember();
    teeSubscribe($u, 'founders_circle'); // cap 8000, franquia 2100
    teeSeed($u, 7500);

    teePolicy()->credit($u, 2100, 'subscription_grant');

    expect(teeBalance($u))->toBe(8000)     // creditou 500
        ->and(teePending($u))->toBe(1600); // pendurou 1600
});

it('pendencia nao empilha: dois ciclos no teto continuam em 1 franquia', function () {
    $u = teeMember();
    teeSubscribe($u, 'black'); // franquia 1000
    teeSeed($u, 5000);         // já no teto

    teePolicy()->credit($u, 1000, 'subscription_grant');
    expect(teePending($u))->toBe(1000);

    teePolicy()->credit($u, 1000, 'subscription_grant'); // substitui, não soma
    expect(teePending($u))->toBe(1000)
        ->and(teeBalance($u))->toBe(5000);
});

it('gasto consome a pendencia parcialmente e ela nao expira', function () {
    $u = teeMember();
    teeSubscribe($u, 'black');
    teeSeed($u, 5000);
    teePolicy()->credit($u, 1000, 'subscription_grant'); // pending 1000, saldo no teto

    app(TokenService::class)->debit($u, 300, 'spend_tip');

    // O gasto liberou 300 de espaço → a policy liberou 300 da pendência no mesmo
    // caminho do débito. Saldo volta ao teto, pendência cai para 700 (não expira).
    expect(teeBalance($u))->toBe(5000)
        ->and(teePending($u))->toBe(700);

    // A liberação é uma LINHA subscription_grant real no ledger append-only.
    $release = TokenLedger::where('reference_type', 'pending_release')->latest('id')->first();
    expect($release)->not->toBeNull()
        ->and($release->entry_type)->toBe('subscription_grant')
        ->and($release->amount)->toBe(300);
});

it('cada gasto libera no maximo o espaco que ele abriu, nunca a pendencia inteira', function () {
    // Prova determinística da invariante que o lock protege sob concorrência: dois
    // gastos de 100 liberam 100 cada (não 1000 cada). Se houvesse double-release,
    // a pendência despencaria além do espaço aberto.
    $u = teeMember();
    teeSubscribe($u, 'black');
    teeSeed($u, 5000);
    teePolicy()->credit($u, 1000, 'subscription_grant'); // pending 1000

    app(TokenService::class)->debit($u, 100, 'spend_tip');
    expect(teePending($u))->toBe(900)->and(teeBalance($u))->toBe(5000);

    app(TokenService::class)->debit($u, 100, 'spend_tip');
    expect(teePending($u))->toBe(800)->and(teeBalance($u))->toBe(5000);
});

// ─── SPLIT E ARREDONDAMENTO (M.13.7) ─────────────────────────────────────────

it('credito + retencao == valor para toda faixa 5..1000 nas taxas 70/75/80', function () {
    $policy = teePolicy();
    // live=70, gift=80, content=80 cobrem as taxas (presente subiu p/ 80 em 21/08/2026).
    foreach (['live', 'gift', 'content'] as $key) {
        for ($valor = 5; $valor <= 1000; $valor++) {
            $s = $policy->applyRate($valor, $key);
            // Split DECIMAL EXATO (bcmath): credited + retained == valor SEMPRE, sem
            // arredondamento (M.13.7 superado 19/08/2026). Soma por TokenMath, nunca `+`.
            expect(\App\Support\TokenMath::add($s['credited'], $s['retained']))->toBe(\App\Support\TokenMath::of($valor))
                ->and(\App\Support\TokenMath::isPositive($s['credited']))->toBeTrue();
        }
    }
});

it('a taxa gravada na linha congela e nao muda quando a config muda', function () {
    $perf = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    // content=80: 100 → credita 80, applied_rate 80.
    $line = teePolicy()->creditWithSplit($perf, 100, 'content', 'tip_credit', description: 'x');
    expect($line->amount)->toBe(80)->and($line->applied_rate)->toBe(80);

    // Config muda depois — a linha existente NÃO recalcula.
    config(['monetization.split_rates.content.rate' => 50]);
    $line->refresh();
    expect($line->amount)->toBe(80)->and($line->applied_rate)->toBe(80);

    // Um crédito NOVO usa a taxa nova.
    $novo = teePolicy()->creditWithSplit($perf, 100, 'content', 'tip_credit', description: 'y');
    expect($novo->amount)->toBe(50)->and($novo->applied_rate)->toBe(50);
});

// ─── CHAT (M.13.1 SUPERADO 19/08/2026: split 80/20, não mais crédito fixo) ────

it('credita 80% do custo de abertura à performer (split decimal), não mais o fixo de 1', function () {
    // M.13.1 substituído pela economia decimal: o chat entrou no split 80/20 como
    // todo evento. Custo 2 → performer 1,6000; custo 1 (Black/FC) → 0,8000. O
    // crédito fixo de 1 (chatOpenPerformerCredit) foi REMOVIDO — era a única fonte
    // sistemática de retenção indevida (50% com contrato dizendo 80%).
    expect(teePolicy()->applyRate(2, 'chat'))->toMatchArray(['credited' => '1.6000', 'retained' => '0.4000', 'rate' => 80]);
    expect(teePolicy()->applyRate(1, 'chat'))->toMatchArray(['credited' => '0.8000', 'retained' => '0.2000', 'rate' => 80]);
    expect(method_exists(teePolicy(), 'chatOpenPerformerCredit'))->toBeFalse();
});

it('cobra 2 tokens de nao-assinante e Expl/Ins/Pres, 1 de Black e FC', function () {
    $na = teeMember();
    expect(teePolicy()->chatCost($na))->toBe(2);

    foreach (['explorador' => 2, 'insider' => 2, 'prestige' => 2, 'black' => 1, 'founders_circle' => 1] as $slug => $cost) {
        $u = teeMember();
        teeSubscribe($u, $slug);
        expect(teePolicy()->chatCost($u))->toBe($cost);
    }
});

// ─── PACOTES E DESCONTOS (M.13.2 / M.13.3 / M.13.11) ─────────────────────────

it('Starter da exatamente R$1,00/token', function () {
    $p = config('monetization.packages.starter');
    expect(round($p['price_cents'] / $p['tokens'] / 100, 2))->toBe(1.00);
});

it('nenhuma das 20 combinacoes de pacote x desconto cai abaixo de R$0,625/token', function () {
    $packages = config('monetization.packages');
    $discounts = config('monetization.discounts_by_tier');
    $floor = config('monetization.min_effective_price_per_token');

    expect($discounts)->toHaveCount(5)->and($packages)->toHaveCount(4);

    foreach ($packages as $pkgName => $pkg) {
        foreach ($discounts as $tier => $pct) {
            $effectivePerToken = ($pkg['price_cents'] * (100 - $pct) / 100) / 100 / $pkg['tokens'];
            expect($effectivePerToken)->toBeGreaterThanOrEqual($floor, "{$pkgName} x {$tier}");
        }
    }
});

// ─── PRESENTES (M.13.6) ──────────────────────────────────────────────────────

it('rejeita preco de presente que nao e multiplo de 4 e aceita os que sao', function () {
    $policy = teePolicy();
    foreach ([1, 2, 3, 5, 6, 7, 10, 13, 199, 0] as $bad) {
        expect($policy->isValidGiftPrice($bad))->toBeFalse("preço {$bad}");
    }
    foreach ([4, 12, 40, 100, 200, 400] as $ok) {
        expect($policy->isValidGiftPrice($ok))->toBeTrue("preço {$ok}");
    }
});

// ─── PAYOUT (M.13.5) ─────────────────────────────────────────────────────────

it('documenta a taxa de payout de R$0,60 por token', function () {
    expect(teePolicy()->payoutRatePerToken())->toBe(0.60)
        ->and(config('monetization.payout_rate_per_token'))->toBe(0.60);
});

// ─── GATE DE COMPRA vs WEBHOOK (M.13.9) ──────────────────────────────────────

it('barra no checkout a compra que estouraria o teto, antes de criar cobranca', function () {
    $u = teeMember();
    teeSeed($u, 4900); // cap 5000
    $package = TokenPackage::create([
        'slug' => 'x', 'name' => 'X', 'tokens' => 200, 'bonus' => 0,
        'price_cents' => 1990, 'active' => true, 'sort_order' => 99,
    ]);

    expect(fn () => app(PaymentService::class)->createPayment($u, $package))
        ->toThrow(CapExceededException::class);

    // Nenhuma cobrança criada — o gate roda antes do Asaas.
    expect(Payment::where('user_id', $u->id)->count())->toBe(0);
});

it('webhook credita cheio mesmo com saldo ja acima do teto (dinheiro pago nunca retido)', function () {
    $u = teeMember();
    teeSeed($u, 5000); // no teto

    $line = teePolicy()->creditPaidPurchase($u, 200, 'payment', 123, 'Purchase: 200 tokens');

    expect(teeBalance($u))->toBe(5200)
        ->and($line->entry_type)->toBe('purchase')
        ->and($line->amount)->toBe(200);
});

// ─── AVISO DE APROXIMAÇÃO DO TETO (M.13.8) ───────────────────────────────────

it('dispara o aviso no limiar certo de cada tier e do nao-assinante', function () {
    // Não-assinante: threshold 4500 de espaço → dispara com saldo >= 500 (cap 5000).
    $na = teeMember();
    teeSeed($na, 499);
    expect(teePolicy()->approachingCap($na))->toBeFalse();
    teeSeed($na, 1); // 500
    expect(teePolicy()->approachingCap($na))->toBeTrue();

    // Black: 2×1000 = 2000 de espaço → dispara com saldo >= 3000 (cap 5000).
    $black = teeMember();
    teeSubscribe($black, 'black');
    teeSeed($black, 2999);
    expect(teePolicy()->approachingCap($black))->toBeFalse();
    teeSeed($black, 1); // 3000
    expect(teePolicy()->approachingCap($black))->toBeTrue();

    // FC: 2×2100 = 4200 de espaço → dispara com saldo >= 3800 (cap 8000).
    $fc = teeMember();
    teeSubscribe($fc, 'founders_circle');
    teeSeed($fc, 3799);
    expect(teePolicy()->approachingCap($fc))->toBeFalse();
    teeSeed($fc, 1); // 3800
    expect(teePolicy()->approachingCap($fc))->toBeTrue();
});

// ─── ARQUITETURA: teto não é burlável por caminho fora da policy ─────────────

it('nenhum credito cap-critico chama TokenService fora da TokenCreditPolicy', function () {
    // "Dona única" vira invariante, não convenção: nenhum arquivo de app pode
    // chamar tokenService->credit(...) com purchase/bonus/subscription_grant a não
    // ser a própria policy (que é o escritor legítimo por baixo).
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && $file->getFilename() !== 'TokenCreditPolicy.php') {
            $files[] = $file->getPathname();
        }
    }

    $offenders = [];
    foreach ($files as $path) {
        $code = file_get_contents($path);
        if (preg_match('/tokenService->credit\s*\([^;]*?[\'"](purchase|bonus|subscription_grant)[\'"]/s', $code)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});
