<?php

use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Services\ChatAccessService;
use App\Services\PayoutService;
use App\Services\TokenCreditPolicy;
use App\Services\TokenService;
use App\Support\TokenMath;
use Illuminate\Support\Str;

/**
 * Economia de tokens DECIMAL EXATA (19/08/2026) — DECIMAL(20,4) + bcmath (escala 4),
 * split percentual para TODOS os fluxos, arredondamento SÓ no payout (floor, sobra
 * preservada). Substitui M.13.1 (crédito fixo de chat) e M.13.7 (round-half-up).
 *
 * "Nunca float" continua valendo: bcmath é aritmética decimal exata. Estes testes
 * provam que o caminho REAL usa bcmath (não coerção a float) e que a Regra Única de
 * Arredondamento (R1–R4) vale.
 */

// ── Splits exatos por fluxo (os números pedidos pelo PO) ─────────────────────

it('computes every percentage split as EXACT decimal (no intdiv, no round-half-up)', function () {
    $p = app(TokenCreditPolicy::class);

    expect($p->applyRate(3, 'tip'))->toMatchArray(['credited' => '2.4000', 'retained' => '0.6000', 'rate' => 80]);
    expect($p->applyRate(7, 'content'))->toMatchArray(['credited' => '5.6000', 'retained' => '1.4000', 'rate' => 80]);
    expect($p->applyRate(2, 'chat'))->toMatchArray(['credited' => '1.6000', 'retained' => '0.4000', 'rate' => 80]);
    expect($p->applyRate(13, 'call'))->toMatchArray(['credited' => '9.1000', 'retained' => '3.9000', 'rate' => 70]);
    expect($p->applyRate(7, 'gift'))->toMatchArray(['credited' => '5.6000', 'retained' => '1.4000', 'rate' => 80]); // presente subiu para 80/20 (21/08/2026)
});

// ── A PROVA central: 3 × 1,60 == 4,8000 exato pelo caminho REAL (bcmath) ─────

it('sums three real 1.60 chat-open credits to EXACTLY 4.8000 (bcmath path, not float)', function () {
    $performer = chatPerformer();
    $performerUser = $performer->user;

    foreach (range(1, 3) as $i) {
        [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
        grantChatAccess($member, $conversation); // membro paga 2 → performer +1,6000
    }

    $balance = app(TokenService::class)->balance($performerUser);

    expect($balance)->toBe('4.8000')          // EXATO
        ->and($balance)->not->toBe('4.7999')
        ->and($balance)->not->toBe('4.8000000001')
        ->and($balance)->not->toBe(3);         // NÃO o modelo antigo (fixo 1 → 3)

    $walletId = TokenWallet::where('user_id', $performerUser->id)->value('id');
    $credits = TokenLedger::where('wallet_id', $walletId)
        ->where('entry_type', 'chat_access_credit')->orderBy('id')->get();

    expect($credits)->toHaveCount(3);
    $credits->each(fn ($row) => expect($row->amount)->toBe('1.6000')->and((int) $row->applied_rate)->toBe(80));

    // Invariante: balance == SUM(amount), exato (soma DB decimal).
    $sum = TokenMath::of(TokenLedger::where('wallet_id', $walletId)->sum('amount'));
    expect($sum)->toBe('4.8000')
        ->and(TokenMath::cmp($sum, TokenWallet::whereKey($walletId)->value('balance')))->toBe(0);
});

// ── R4: nenhum fluxo cria ou destrói token (débitos + créditos + comissão = 0) ─

it('conserves tokens in every split flow: memberDebit + performerCredit + platformCut == 0', function () {
    $policy = app(TokenCreditPolicy::class);

    foreach ([['tip', 3], ['content', 7], ['chat', 2], ['call', 13], ['gift', 7], ['tip', 999]] as [$rateKey, $gross]) {
        $s = $policy->applyRate($gross, $rateKey);
        // performerCredit + platformCut == gross (crédito + retenção = bruto).
        expect(TokenMath::add($s['credited'], $s['retained']))->toBe(TokenMath::of($gross));
        // Forma de conservação: (−bruto) + crédito + comissão == 0.
        $zero = TokenMath::add(TokenMath::sub(0, $gross), TokenMath::add($s['credited'], $s['retained']));
        expect($zero)->toBe('0.0000');
    }

    // E no caminho REAL de uma abertura de chat: a linha de débito do membro casa
    // com o crédito da performer + a retenção implícita da Limen.
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
    grantChatAccess($member, $conversation);

    $memberWallet = TokenWallet::where('user_id', $member->id)->value('id');
    $spend = TokenLedger::where('wallet_id', $memberWallet)->where('entry_type', 'spend_chat_access')->value('amount'); // -2
    $perfWallet = TokenWallet::where('user_id', $performer->user->id)->value('id');
    $credit = TokenLedger::where('wallet_id', $perfWallet)->where('entry_type', 'chat_access_credit')->value('amount'); // 1.6
    $platformCut = TokenMath::sub(2, $credit); // 0.4
    expect(TokenMath::add($spend, TokenMath::add($credit, $platformCut)))->toBe('0.0000');
});

// ── Saldo do membro é INTEIRO por construção; só a performer fraciona ─────────

it('keeps the member balance INTEGER through every operation; only the performer fractions', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 100);

    expect(app(TokenService::class)->balance($member))->toBeInt(); // 100 (após unlock de 15)
    grantChatAccess($member, $conversation);
    expect(app(TokenService::class)->balance($member))->toBeInt(); // 98 — sempre inteiro

    // A performer, que recebeu o split, lê STRING decimal exata.
    expect(app(TokenService::class)->balance($performer->user))->toBeString()->toBe('1.6000');
});

// ── Débito sobre saldo fracionário é exato ───────────────────────────────────

it('debits a fractional performer balance exactly (4.8000 - 2 = 2.8000)', function () {
    $performer = chatPerformer();
    $performerUser = $performer->user;
    foreach (range(1, 3) as $i) {
        [$member, $conversation] = chatUnlockedPair($performer, balance: 5);
        grantChatAccess($member, $conversation);
    }
    app(TokenService::class)->debit($performerUser, 2, 'adjustment', description: 'ajuste');
    expect(app(TokenService::class)->balance($performerUser))->toBe('2.8000');
});

// ── R2/R3: payout arredonda UMA vez (floor no centavo), a sobra CONTINUA nela ─

it('payoutBreakdown floors to the centavo and preserves the remainder (4.8733 → R$2.92)', function () {
    $b = app(PayoutService::class)->payoutBreakdown('4.8733');

    expect($b['centavos'])->toBe(292)                 // R$2,92 (floor de 292,398)
        ->and($b['tokens_consumed'])->toBe('4.8666')  // 292 ÷ 60
        ->and($b['remainder'])->toBe('0.0067');       // a SOBRA
    // Nada some: consumido + sobra == o que se pretendia sacar.
    expect(TokenMath::add($b['tokens_consumed'], $b['remainder']))->toBe('4.8733');
});

it('a real payout pays floored reais and the remainder STAYS in her balance (not the Limen)', function () {
    $performer = chatPerformer()->user;
    // Ganho fracionário sacável direto no ledger (tip_credit está no allowlist).
    app(TokenService::class)->credit($performer, '104.8733', 'tip_credit', description: 'ganho');
    expect(app(TokenService::class)->balance($performer))->toBe('104.8733');

    $payout = app(PayoutService::class)->createAndSendPayout($performer, '104.8733', 'perf@example.com', 'email');

    // Paga o floor ao centavo: 104,8733 × 0,60 = R$62,92398 → R$62,92.
    expect((string) $payout->amount_brl)->toBe('62.92')
        ->and($payout->tokens)->toBe('104.8666');     // tokens efetivamente monetizados

    // A reserva debitou SÓ o consumido; a sobra 0,0067 continua no saldo DELA.
    expect(app(TokenService::class)->balance($performer))->toBe('0.0067');

    // A sobra não foi para lugar nenhum: o ledger dela ainda fecha (balance == SUM).
    $walletId = TokenWallet::where('user_id', $performer->id)->value('id');
    expect(TokenMath::cmp(
        TokenMath::of(TokenLedger::where('wallet_id', $walletId)->sum('amount')),
        TokenWallet::whereKey($walletId)->value('balance'),
    ))->toBe(0);
});

// ── Migration: dado inteiro sobrevive à conversão sem perda ───────────────────

it('preserves integer balances losslessly after the decimal conversion (balance == SUM)', function () {
    // Simula o que a migration faz com dados existentes: valores inteiros lidos de
    // volta EXATOS como int, e balance == SUM(amount).
    $user = \App\Models\User::factory()->create();
    app(TokenService::class)->credit($user, 500, 'purchase');
    app(TokenService::class)->debit($user, 137, 'spend_tip');

    $walletId = TokenWallet::where('user_id', $user->id)->value('id');
    expect(app(TokenService::class)->balance($user))->toBe(363) // inteiro, sem casas
        ->and(TokenLedger::where('wallet_id', $walletId)->where('entry_type', 'purchase')->value('amount'))->toBe(500)
        ->and(TokenMath::of(TokenLedger::where('wallet_id', $walletId)->sum('amount')))->toBe('363.0000');
});

// ── Concorrência: replay da mesma chave cobra uma vez só ─────────────────────

it('charges a chat open only once under a replayed idempotency key (no double charge)', function () {
    $performer = chatPerformer();
    [$member, $conversation] = chatUnlockedPair($performer, balance: 10);
    $key = (string) Str::uuid();

    // Duas chamadas com a MESMA chave (o proxy testável do duplo-submit): a 2ª é replay.
    app(ChatAccessService::class)->openOrRenew($conversation, $member->fresh(), $key);
    app(ChatAccessService::class)->openOrRenew($conversation, $member->fresh(), $key);

    // Membro cobrado UMA vez (10 + 15 helper − 15 unlock − 2 chat = 8); performer +1,6000 uma vez.
    expect(app(TokenService::class)->balance($member))->toBe(8)
        ->and(app(TokenService::class)->balance($performer->user))->toBe('1.6000');
});
