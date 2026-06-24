<?php

use App\Exceptions\InsufficientBalanceException;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\TokenService;

beforeEach(function () {
    $this->service = new TokenService();
    $this->user = User::factory()->create();
});

it('credits and debits correctly, with matching balance and ledger', function () {
    $this->service->credit($this->user, 500, 'purchase', description: 'buy tokens');

    expect($this->service->balance($this->user))->toBe(500);

    $debitEntry = $this->service->debit($this->user, 200, 'spend_tip', description: 'tip');

    expect($this->service->balance($this->user))->toBe(300);
    expect($debitEntry->balance_after)->toBe(300);
    expect($debitEntry->amount)->toBe(-200);

    $ledger = TokenLedger::where('wallet_id', $this->user->tokenWallet->id)
        ->orderBy('id')
        ->get();

    expect($ledger)->toHaveCount(2);
    expect($ledger[0]->amount)->toBe(500);
    expect($ledger[0]->balance_after)->toBe(500);
    expect($ledger[1]->amount)->toBe(-200);
    expect($ledger[1]->balance_after)->toBe(300);
});

it('throws exception and saves nothing when debiting above balance', function () {
    $this->service->credit($this->user, 100, 'purchase');

    try {
        $this->service->debit($this->user, 200, 'spend_tip');
    } catch (InsufficientBalanceException) {
        // expected
    }

    expect($this->service->balance($this->user))->toBe(100);

    $ledger = TokenLedger::where('wallet_id', $this->user->tokenWallet->id)->get();
    expect($ledger)->toHaveCount(1);
});

it('prevents updating ledger entries', function () {
    $this->service->credit($this->user, 100, 'purchase');
    $entry = TokenLedger::first();

    $entry->amount = 9999;

    expect(fn () => $entry->save())->toThrow(RuntimeException::class, 'immutable');
});

it('prevents deleting ledger entries', function () {
    $this->service->credit($this->user, 100, 'purchase');
    $entry = TokenLedger::first();

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'cannot be deleted');
});
