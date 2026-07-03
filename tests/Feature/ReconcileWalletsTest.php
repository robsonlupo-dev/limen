<?php

use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;

function makeWalletWithLedger(int $balance, array $entryAmounts): TokenWallet
{
    $user = User::factory()->create();
    $wallet = TokenWallet::create(['user_id' => $user->id, 'balance' => $balance]);

    foreach ($entryAmounts as $amount) {
        TokenLedger::create([
            'wallet_id' => $wallet->id,
            'entry_type' => $amount >= 0 ? 'tip_credit' : 'spend_tip',
            'amount' => $amount,
            'balance_after' => $balance,
            'description' => 'seed',
        ]);
    }

    return $wallet;
}

it('inserts a backfill adjustment equal to the diff and never touches the balance', function () {
    // Residue: wallet holds 565 but only a 65-token tip_credit is in the ledger.
    $wallet = makeWalletWithLedger(565, [65]);

    $this->artisan('tokens:reconcile-wallets')->assertSuccessful();

    $adjustment = TokenLedger::where('wallet_id', $wallet->id)
        ->where('entry_type', 'staging_seed_backfill')
        ->first();

    expect($adjustment)->not->toBeNull();
    expect($adjustment->amount)->toBe(500);
    expect($adjustment->balance_after)->toBe(565);

    // Balance is unchanged; ledger sum now equals it.
    expect((int) $wallet->fresh()->balance)->toBe(565);
    expect((int) TokenLedger::where('wallet_id', $wallet->id)->sum('amount'))->toBe(565);
});

it('is idempotent — a second run inserts nothing', function () {
    $wallet = makeWalletWithLedger(565, [65]);

    $this->artisan('tokens:reconcile-wallets')->assertSuccessful();
    $countAfterFirst = TokenLedger::where('wallet_id', $wallet->id)->count();

    $this->artisan('tokens:reconcile-wallets')->assertSuccessful();
    $countAfterSecond = TokenLedger::where('wallet_id', $wallet->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('leaves a consistent wallet untouched', function () {
    $wallet = makeWalletWithLedger(100, [100]);

    $this->artisan('tokens:reconcile-wallets')->assertSuccessful();

    expect(TokenLedger::where('wallet_id', $wallet->id)->where('entry_type', 'staging_seed_backfill')->exists())->toBeFalse();
});

it('dry-run reports the mismatch but writes nothing', function () {
    $wallet = makeWalletWithLedger(565, [65]);

    $this->artisan('tokens:reconcile-wallets --dry-run')->assertSuccessful();

    expect(TokenLedger::where('wallet_id', $wallet->id)->where('entry_type', 'staging_seed_backfill')->exists())->toBeFalse();
});

it('skips a wallet whose balance is below the ledger sum (not seed residue)', function () {
    // balance 30 but ledger sums to 100 -> negative diff, must be left untouched.
    $wallet = makeWalletWithLedger(30, [100, -70, 30, -30]);
    expect((int) TokenLedger::where('wallet_id', $wallet->id)->sum('amount'))->toBe(30);

    // Make it genuinely negative: balance below ledger sum.
    $wallet->update(['balance' => 10]);

    $this->artisan('tokens:reconcile-wallets')->assertSuccessful();

    expect(TokenLedger::where('wallet_id', $wallet->id)->where('entry_type', 'staging_seed_backfill')->exists())->toBeFalse();
});

it('reconciles a negative diff only when --allow-negative is passed', function () {
    $wallet = makeWalletWithLedger(100, [100]);
    $wallet->update(['balance' => 40]); // balance now below ledger sum (100)

    $this->artisan('tokens:reconcile-wallets --allow-negative')->assertSuccessful();

    $adjustment = TokenLedger::where('wallet_id', $wallet->id)
        ->where('entry_type', 'staging_seed_backfill')
        ->first();

    expect($adjustment)->not->toBeNull();
    expect($adjustment->amount)->toBe(-60);
    expect((int) TokenLedger::where('wallet_id', $wallet->id)->sum('amount'))->toBe(40);
});

it('refuses to run in production without --force', function () {
    app()->detectEnvironment(fn () => 'production');
    $wallet = makeWalletWithLedger(565, [65]);

    $this->artisan('tokens:reconcile-wallets')->assertFailed();

    expect(TokenLedger::where('wallet_id', $wallet->id)->where('entry_type', 'staging_seed_backfill')->exists())->toBeFalse();
});
