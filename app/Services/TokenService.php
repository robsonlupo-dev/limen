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

    /**
     * Crédito bruto no ledger append-only. É o ESCRITOR de baixo nível; a decisão
     * de teto/split/pendência é da `TokenCreditPolicy` (a dona única — M.13). Os
     * caminhos de crédito cap-críticos (purchase/bonus/subscription_grant) devem
     * passar pela policy, nunca chamar isto direto (travado por teste de arquitetura).
     *
     * `$appliedRate` congela a taxa de split percentual na linha (M.13.7); null em
     * todo crédito que não é split (grant, purchase, chat fixo, refund…).
     */
    public function credit(
        User $user,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?int $appliedRate = null,
    ): TokenLedger {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $description, $appliedRate) {
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
                'applied_rate' => $appliedRate,
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

            $entry = TokenLedger::create([
                'wallet_id' => $wallet->id,
                'entry_type' => $type,
                'amount' => -$amount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);

            // Fila de pendência (M.13.8): o gasto liberou espaço sob o teto — a
            // policy credita o que couber da franquia pendente, na MESMA transação
            // e sobre o wallet JÁ TRAVADO (sem re-consultar), então dois gastos
            // concorrentes serializam no lock e nunca liberam a mesma pendência
            // duas vezes. No-op quando não há pendência. Atômico com o débito por
            // desenho: "mesma disciplina do débito atômico" (task 1b) — uma falha
            // aqui reverte o gasto e o usuário retenta, nunca perde a pendência.
            app(TokenCreditPolicy::class)->releaseAfterDebit($user, $wallet);

            return $entry;
        });
    }
}
