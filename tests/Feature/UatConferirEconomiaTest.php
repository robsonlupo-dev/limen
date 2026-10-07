<?php

use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Comando de auditoria de economia do UAT (uat:conferir-economia). Read-only. Verifica
 * que ele PASSA quando os splits batem com a taxa esperada e FALHA quando um *_credit foi
 * gravado com applied_rate divergente. Prefixo `uce`.
 */
function uceWallet(int $balance): TokenWallet
{
    $u = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return TokenWallet::create(['user_id' => $u->id, 'balance' => $balance]);
}

function uceCredit(TokenWallet $w, string $type, float $amount, ?int $rate, float $balanceAfter): void
{
    TokenLedger::create([
        'wallet_id' => $w->id,
        'entry_type' => $type,
        'amount' => $amount,
        'applied_rate' => $rate,
        'balance_after' => $balanceAfter,
    ]);
}

it('passa quando os splits batem com a ECONOMIA', function () {
    $w = uceWallet(80);
    // Gorjeta 80/20 correta: bruto 100, crédito 80, rate 80.
    uceCredit($w, 'tip_credit', 80, 80, 80);

    $this->artisan('uat:conferir-economia')
        ->assertExitCode(0);
});

it('ignora lançamentos legados sem applied_rate (não falha)', function () {
    $w = uceWallet(120);
    uceCredit($w, 'tip_credit', 80, 80, 80);   // atual, rate 80
    uceCredit($w, 'tip_credit', 40, null, 120); // legado, sem rate → ignorado

    $this->artisan('uat:conferir-economia')
        ->assertExitCode(0);
});

it('falha quando um crédito tem applied_rate divergente da taxa esperada', function () {
    $w = uceWallet(130);
    uceCredit($w, 'tip_credit', 80, 80, 80);     // ok
    uceCredit($w, 'gift_credit', 50, 70, 130);   // presente deveria ser 80, veio 70 → FALHA

    $this->artisan('uat:conferir-economia')
        ->assertExitCode(1);
});

it('falha quando o balance diverge da soma do ledger (bug de dinheiro)', function () {
    $w = uceWallet(999);                          // saldo não bate com o ledger
    uceCredit($w, 'tip_credit', 80, 80, 80);

    $this->artisan('uat:conferir-economia')
        ->assertExitCode(1);
});
