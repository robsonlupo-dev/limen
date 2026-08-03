<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AuditLog;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\FanAlias;
use Illuminate\Support\Facades\DB;

class TipService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
    ) {}

    public function send(
        User $consumer,
        PerformerProfile $performerProfile,
        int $amount,
        string $idempotencyKey,
        ?string $message = null,
    ): Tip {
        // Fast-path idempotency check (outside transaction, no locks)
        $existing = Tip::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($consumer, $performerProfile, $amount, $idempotencyKey, $message) {
            $performerUser = $performerProfile->user;

            // Guard against self-tipping at service layer
            if ($consumer->id === $performerUser->id) {
                throw new \InvalidArgumentException('Cannot tip yourself.');
            }

            // Ensure wallets exist before locking
            TokenWallet::firstOrCreate(['user_id' => $consumer->id], ['balance' => 0]);
            TokenWallet::firstOrCreate(['user_id' => $performerUser->id], ['balance' => 0]);

            // Lock wallets in ascending user_id order to prevent deadlock
            $wallets = TokenWallet::whereIn('user_id', [$consumer->id, $performerUser->id])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            // Re-check idempotency inside the transaction after acquiring locks
            $existing = Tip::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $consumerWallet = $wallets->get($consumer->id);

            if (! $consumerWallet || $consumerWallet->balance < $amount) {
                throw new InsufficientBalanceException($amount, $consumerWallet?->balance ?? 0);
            }

            // Split por TIPO DE EVENTO (M.13.6): gorjeta é 80/20, round-half-up,
            // independente do `split_pct` da performer (que era o modelo antigo).
            // A policy é a dona única do cálculo e congela a taxa (applied_rate=80)
            // na linha do ledger. `split_pct` NÃO é mais lido aqui.
            $split = $this->creditPolicy->applyRate($amount, 'tip');
            $performerAmount = $split['credited'];
            $platformAmount = $split['retained'];

            $consumerEntry = $this->tokenService->debit(
                $consumer,
                $amount,
                'spend_tip',
                Tip::class,
                null,
                "Gorjeta para {$performerProfile->stage_name}",
            );

            // Crédito da performer pela policy (never-cap tip_credit + applied_rate).
            // Descrição por FanAlias, nunca o id cru do membro (disciplina do
            // FanAlias; achado da revisão do PR #130 sobre descrições de crédito).
            $performerEntry = $this->creditPolicy->creditWithSplit(
                $performerUser,
                $amount,
                'tip',
                'tip_credit',
                Tip::class,
                null,
                'Gorjeta recebida de '.FanAlias::label($performerProfile->id, $consumer->id),
            );

            $tip = Tip::create([
                'consumer_id' => $consumer->id,
                'performer_profile_id' => $performerProfile->id,
                'amount' => $amount,
                'performer_amount' => $performerAmount,
                'platform_amount' => $platformAmount,
                'message' => $message,
                'idempotency_key' => $idempotencyKey,
                'consumer_ledger_id' => $consumerEntry->id,
                'performer_ledger_id' => $performerEntry->id,
            ]);

            $performerProfile->increment('tips_count');

            AuditLog::create([
                'user_id' => $consumer->id,
                'action' => 'tip.sent',
                'subject_type' => Tip::class,
                'subject_id' => $tip->id,
                'ip' => request()->ip(),
                'metadata' => [
                    'amount' => $amount,
                    'performer_profile_id' => $performerProfile->id,
                    'performer_amount' => $performerAmount,
                    'platform_amount' => $platformAmount,
                ],
            ]);

            AuditLog::create([
                'user_id' => $performerUser->id,
                'action' => 'tip.received',
                'subject_type' => Tip::class,
                'subject_id' => $tip->id,
                'ip' => null,
                'metadata' => [
                    'amount' => $performerAmount,
                    'consumer_id' => $consumer->id,
                ],
            ]);

            return $tip;
        });
    }
}
