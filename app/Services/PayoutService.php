<?php

namespace App\Services;

use App\Exceptions\PayoutNotAllowedException;
use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayoutService
{
    private const MIN_TOKENS = 500;

    private const MAX_TOKENS = 50000;

    public function __construct(
        private AsaasClientInterface $asaas,
        private TokenService $tokenService,
    ) {}

    public function requestPayout(User $performer, int $tokens, string $pixKey, string $pixKeyType): Payout
    {
        $profile = $performer->performerProfile;

        if (! $profile || ! $profile->is_verified) {
            throw new PayoutNotAllowedException('Complete a verificação de identidade para sacar.');
        }

        if ($tokens < self::MIN_TOKENS || $tokens > self::MAX_TOKENS) {
            throw new \InvalidArgumentException('Token amount out of allowed range.');
        }

        $centavos = $this->calculatePayoutCentavos($tokens, $profile->split_pct);
        $amountBrl = sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);

        $payout = DB::transaction(function () use ($performer, $tokens, $pixKey, $pixKeyType, $amountBrl) {
            $payout = Payout::create([
                'performer_id' => $performer->id,
                'tokens' => $tokens,
                'amount_brl' => $amountBrl,
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            $this->tokenService->debit(
                $performer,
                $tokens,
                'payout_reserve',
                'payout',
                $payout->id,
                "Saque solicitado: {$tokens} tokens",
            );

            return $payout;
        });

        Audit::log('payout.requested', $payout, [
            'tokens' => $tokens,
            'amount_brl' => $amountBrl,
        ]);

        try {
            $transfer = $this->asaas->createTransfer([
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'value' => (float) $amountBrl,
                'description' => "Limen payout #{$payout->id}",
                'external_reference' => "payout_{$payout->id}",
            ]);

            $payout->update([
                'asaas_transfer_id' => $transfer['id'] ?? null,
                'status' => 'processing',
            ]);

            Audit::log('payout.processing', $payout, [
                'asaas_transfer_id' => $payout->asaas_transfer_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Payout transfer creation failed', [
                'payout_id' => $payout->id,
                'error_class' => get_class($e),
            ]);

            $this->markFailedAndReverse($payout, 'Falha ao criar transferência com o provedor de pagamento.');
        }

        return $payout->fresh();
    }

    public function calculatePayoutCentavos(int $tokens, int $splitPct): int
    {
        return (int) round(($tokens * 99 * $splitPct) / 1000);
    }

    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['event'] ?? null;
        $transferId = $payload['transfer']['id'] ?? null;

        if (! $eventType || ! $transferId) {
            return;
        }

        $eventId = $payload['id'] ?? "{$eventType}_{$transferId}";

        if (PaymentEvent::where('provider_event_id', $eventId)->exists()) {
            return;
        }

        $payout = $this->resolvePayoutForTransfer($transferId, $payload);

        try {
            PaymentEvent::create([
                'provider' => 'asaas',
                'provider_event_id' => $eventId,
                'payout_id' => $payout?->id,
                'payload' => $this->redactPayload($payload),
            ]);
        } catch (QueryException) {
            // Another request already recorded this event (unique constraint) — idempotent no-op.
            return;
        }

        if ($payout) {
            if ($eventType === 'TRANSFER_PAID') {
                $this->markPaid($payout);
            } elseif ($eventType === 'TRANSFER_FAILED') {
                $reason = $payload['transfer']['failReason'] ?? ($payload['reason'] ?? 'Transfer failed');
                $this->markFailedAndReverse($payout, $reason);
            }
        } else {
            Log::warning('Transfer webhook for unknown transfer', [
                'transfer_id' => $transferId,
                'event' => $eventId,
            ]);
        }

        PaymentEvent::where('provider_event_id', $eventId)->update(['processed_at' => now()]);
    }

    private function resolvePayoutForTransfer(string $transferId, array $payload): ?Payout
    {
        $payout = Payout::where('asaas_transfer_id', $transferId)->first();

        if ($payout) {
            return $payout;
        }

        // The webhook can race ahead of our own asaas_transfer_id update; fall back to the
        // external reference we sent when creating the transfer so the event isn't stranded.
        $externalReference = $payload['transfer']['externalReference'] ?? null;

        if ($externalReference && str_starts_with($externalReference, 'payout_')) {
            $payoutId = (int) substr($externalReference, strlen('payout_'));
            $payout = Payout::find($payoutId);

            if ($payout && ! $payout->asaas_transfer_id) {
                $payout->update(['asaas_transfer_id' => $transferId]);
            }
        }

        return $payout;
    }

    private function redactPayload(array $payload): array
    {
        if (isset($payload['transfer']['pixAddressKey'])) {
            $payload['transfer']['pixAddressKey'] = '[redacted]';
        }

        return $payload;
    }

    private function markPaid(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // Accept 'pending' too: a TRANSFER_PAID webhook can race ahead of our
            // own update to 'processing' (or the process may die right after
            // createTransfer). A paid transfer must not get stranded as unpaid.
            if (! in_array($locked->status, ['processing', 'pending'], true)) {
                return;
            }

            $locked->update([
                'status' => 'paid',
                'processed_at' => now(),
            ]);

            Audit::log('payout.paid', $locked);
        });
    }

    private function markFailedAndReverse(Payout $payout, ?string $reason): void
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            if (in_array($locked->status, ['paid', 'failed', 'cancelled'], true)) {
                return;
            }

            $alreadyReversed = TokenLedger::where('reference_type', 'payout')
                ->where('reference_id', $locked->id)
                ->where('entry_type', 'payout_reversal')
                ->exists();

            if (! $alreadyReversed) {
                $this->tokenService->credit(
                    $locked->performer,
                    $locked->tokens,
                    'payout_reversal',
                    'payout',
                    $locked->id,
                    "Estorno do saque #{$locked->id}",
                );
            }

            $locked->update([
                'status' => 'failed',
                'failure_reason' => $reason,
            ]);

            Audit::log('payout.failed', $locked, ['reason' => $reason]);
        });
    }
}
