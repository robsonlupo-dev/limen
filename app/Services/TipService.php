<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AuditLog;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TipService
{
    public function __construct(private TokenService $tokenService) {}

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

            if ($performerProfile->split_pct > 100) {
                throw new \RuntimeException('Invalid split configuration.');
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

            $performerAmount = (int) floor($amount * $performerProfile->split_pct / 100);
            $platformAmount = $amount - $performerAmount;

            $consumerEntry = $this->tokenService->debit(
                $consumer,
                $amount,
                'spend_tip',
                Tip::class,
                null,
                "Gorjeta para {$performerProfile->stage_name}",
            );

            $performerEntry = $this->tokenService->credit(
                $performerUser,
                $performerAmount,
                'tip_credit',
                Tip::class,
                null,
                "Gorjeta recebida de consumer #{$consumer->id}",
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
