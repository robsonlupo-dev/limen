<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TokenService
{
    public function balance(User $user): int
    {
        return TokenWallet::where('user_id', $user->id)->value('balance') ?? 0;
    }

    public function credit(
        User $user,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): TokenLedger {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $description) {
            TokenWallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();

            $newBalance = $wallet->balance + $amount;
            $wallet->update(['balance' => $newBalance]);

            return TokenLedger::create([
                'wallet_id' => $wallet->id,
                'entry_type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    public function debit(
        User $user,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): TokenLedger {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $description) {
            TokenWallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                throw new InsufficientBalanceException($amount, $wallet->balance);
            }

            $newBalance = $wallet->balance - $amount;
            $wallet->update(['balance' => $newBalance]);

            return TokenLedger::create([
                'wallet_id' => $wallet->id,
                'entry_type' => $type,
                'amount' => -$amount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }
}
