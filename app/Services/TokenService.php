<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\TokenMath;
use Illuminate\Support\Facades\DB;

/**
 * Escritor bruto do ledger append-only. Desde a economia decimal (19/08/2026, M.14) o
 * amount/balance são DECIMAL(20,4) e TODA aritmética passa por bcmath
 * (App\Support\TokenMath) — decimal EXATO, nunca float. O saldo canônico é uma STRING
 * de escala 4 ("1500.0000"/"4.8000"). Amounts de entrada aceitam int (custo/pacote
 * inteiro) OU string (fatia fracionária do split); a normalização é interna.
 */
class TokenService
{
    /**
     * Saldo do usuário. INT quando inteiro (o caso do membro, "inteiro por
     * construção", e toda carteira de saldo redondo), STRING decimal exata quando
     * fracionário (carteira de performer que recebeu frações do split — ex.: "4.8000").
     * Aritmética sobre o retorno passa por TokenMath (aceita int|string) — nunca
     * operador nativo, nunca coerção a float.
     */
    public function balance(User $user): int|string
    {
        $raw = TokenWallet::where('user_id', $user->id)->value('balance');

        return $raw === null ? 0 : TokenMath::readable($raw);
    }

    /**
     * Crédito bruto no ledger append-only. É o ESCRITOR de baixo nível; a decisão
     * de teto/split/pendência é da `TokenCreditPolicy` (a dona única — M.13). Os
     * caminhos de crédito cap-críticos (purchase/bonus/subscription_grant) devem
     * passar pela policy, nunca chamar isto direto (travado por teste de arquitetura).
     *
     * `$amount` pode ser inteiro (grant/compra) ou decimal (fatia do split, ex.
     * "1.6000"); é normalizado para escala 4 e somado por bcmath. `$appliedRate`
     * congela a taxa do split percentual na linha (M.13.7 superado por decimal
     * exato); null em todo crédito que não é split.
     */
    public function credit(
        User $user,
        int|string $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?int $appliedRate = null,
    ): TokenLedger {
        $amount = TokenMath::of($amount);

        if (! TokenMath::isPositive($amount)) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $description, $appliedRate) {
            TokenWallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();

            $newBalance = TokenMath::add($wallet->balance, $amount);
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

    /**
     * Débito bruto. `$amount` normalmente inteiro (todo gasto de membro é inteiro);
     * aceita string por simetria. O saldo pode ser fracionário (carteira de
     * performer), então a checagem de suficiência e a subtração são bcmath.
     */
    public function debit(
        User $user,
        int|string $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
    ): TokenLedger {
        $amount = TokenMath::of($amount);

        if (! TokenMath::isPositive($amount)) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $description) {
            TokenWallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0],
            );

            $wallet = TokenWallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (TokenMath::cmp($wallet->balance, $amount) < 0) {
                throw new InsufficientBalanceException($amount, $wallet->balance);
            }

            $newBalance = TokenMath::sub($wallet->balance, $amount);
            $wallet->update(['balance' => $newBalance]);

            $entry = TokenLedger::create([
                'wallet_id' => $wallet->id,
                'entry_type' => $type,
                'amount' => TokenMath::sub(0, $amount), // negativo: "-2.0000"
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);

            // Fila de pendência (M.13.8): o gasto liberou espaço sob o teto — a
            // policy credita o que couber da franquia pendente, na MESMA transação
            // e sobre o wallet JÁ TRAVADO (sem re-consultar), então dois gastos
            // concorrentes serializam no lock e nunca liberam a mesma pendência
            // duas vezes. No-op quando não há pendência. Atômico com o débito.
            app(TokenCreditPolicy::class)->releaseAfterDebit($user, $wallet);

            return $entry;
        });
    }
}
