<?php

namespace App\Services;

use App\Exceptions\PayoutNotAllowedException;
use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\AsaasUnavailableException;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayoutService
{
    private const MIN_TOKENS = 500;

    private const MAX_TOKENS = 50000;

    // Only reconcile payouts that have been in flight for a while, so a normal
    // in-progress transfer and its webhook have had time to arrive first.
    private const RECONCILE_MIN_AGE_MINUTES = 15;

    // How long a payout may stay unresolvable before the reconcile stops retrying it
    // and hands it to a human. The lookup search is not read-after-write, so an empty
    // result right after createTransfer proves nothing — but hours later it is no
    // longer indexing lag, and retrying forever is what stranded the tokens.
    //
    // Counted from unresolved_since (the start of the current streak of failed
    // lookups), never from requested_at: while the gateway is unreachable we defer
    // without spending a lookup at all, and a requested_at deadline would burn away
    // during an outage and then park a whole batch on its first clean lookup.
    private const RECONCILE_REVIEW_AFTER_HOURS = 2;

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
        } catch (AsaasUnavailableException $e) {
            // Ambiguous outcome (timeout / 5xx): Asaas may have created the transfer
            // and be paying the PIX. Reversing here could return tokens for money
            // that actually went out. Leave the payout 'processing' with no transfer
            // id; the webhook (resolves by externalReference) or payouts:reconcile
            // settles it against Asaas.
            Log::error('Payout transfer result unknown; deferring to reconcile', [
                'payout_id' => $payout->id,
                'error_class' => get_class($e),
            ]);

            $payout->update(['status' => 'processing']);
            Audit::log('payout.unconfirmed', $payout);
        } catch (\Throwable $e) {
            // Definitive failure (4xx / invalid request): the transfer was not
            // created, so it is safe to fail and return the reserved tokens.
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
            // Asaas signals a completed transfer with TRANSFER_DONE (status DONE) —
            // NOT "TRANSFER_PAID". Accept the alias too for safety. A cancelled
            // transfer, like a failed one, must reverse the reservation.
            if (in_array($eventType, ['TRANSFER_DONE', 'TRANSFER_PAID'], true)) {
                $this->markPaid($payout);
            } elseif (in_array($eventType, ['TRANSFER_FAILED', 'TRANSFER_CANCELLED'], true)) {
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

    /**
     * Settle payouts left in flight — the safety net for a lost webhook or an
     * ambiguous createTransfer (where we intentionally did NOT reverse). Resolves
     * each against Asaas so tokens are never stranded and money is never double-paid.
     */
    public function reconcile(): void
    {
        $inFlight = Payout::whereIn('status', ['pending', 'processing'])
            ->where('requested_at', '<=', now()->subMinutes(self::RECONCILE_MIN_AGE_MINUTES))
            ->get();

        foreach ($inFlight as $payout) {
            try {
                $this->reconcileOne($payout);
            } catch (AsaasUnavailableException) {
                // Still can't reach Asaas — leave it exactly as is and retry next run.
                Log::warning('Payout reconcile deferred (gateway unavailable)', ['payout_id' => $payout->id]);
            } catch (\Throwable $e) {
                Log::error('Payout reconcile error', [
                    'payout_id' => $payout->id,
                    'error_class' => get_class($e),
                ]);
            }
        }
    }

    private function reconcileOne(Payout $payout): void
    {
        $transfer = $this->locateTransfer($payout);

        if ($transfer === null) {
            // We could NOT positively confirm a transfer. This is not proof one
            // doesn't exist — Asaas search is not read-after-write, and a 4xx like
            // 429/401 on the lookup is an operational hiccup, not "gone". Reversing
            // here could return tokens for a PIX that already went out (double pay),
            // so never auto-reverse.
            //
            // Retrying is right while the result may still be indexing lag, but past
            // RECONCILE_REVIEW_AFTER_HOURS it is not lag anymore and the retry is
            // pure cost: the payout is re-queried every run forever, the performer's
            // tokens sit reserved with only a log line as the signal, and the batch
            // grows monotonically (more requests → more rate limiting → more of
            // these). Escalate to 'needs_review': a terminal state for automation
            // only, which moves no money and still lets a webhook settle it.
            $unresolvedSince = $payout->unresolved_since;

            if ($unresolvedSince === null) {
                $payout->update(['unresolved_since' => now()]);
                $unresolvedSince = $payout->unresolved_since;
            }

            if ($unresolvedSince->gt(now()->subHours(self::RECONCILE_REVIEW_AFTER_HOURS))) {
                Log::warning('Payout unresolved by reconcile — will retry', ['payout_id' => $payout->id]);
                Audit::log('payout.reconcile_unresolved', $payout);

                return;
            }

            $this->markNeedsReview($payout);

            return;
        }

        // Located: any earlier streak is over, so the next one starts from scratch.
        if ($payout->unresolved_since !== null) {
            $payout->update(['unresolved_since' => null]);
        }

        if (! $payout->asaas_transfer_id && ! empty($transfer['id'])) {
            $payout->update(['asaas_transfer_id' => $transfer['id']]);
        }

        $status = $transfer['status'] ?? '';

        // Only an EXPLICIT terminal status from Asaas moves money: DONE credits the
        // performer's payout as paid; FAILED/CANCELLED returns the reserved tokens.
        if ($status === 'DONE') {
            $this->markPaid($payout);
        } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $this->markFailedAndReverse($payout, $transfer['failReason'] ?? 'Transferência falhou no provedor.');
        }
        // PENDING / BANK_PROCESSING: still moving — leave for a later run.
    }

    private function locateTransfer(Payout $payout): ?array
    {
        if ($payout->asaas_transfer_id) {
            // A recorded id means the transfer WAS created. Never swallow a lookup
            // failure into "not found" here — let it propagate so reconcile() defers
            // (a 404/429/401 must not turn into a reversal of a possibly-paid PIX).
            return $this->asaas->getTransfer($payout->asaas_transfer_id);
        }

        // Ambiguous payout: we never recorded an id. Find it by the external
        // reference we sent. Filter client-side so an unfiltered list response
        // can never make us act on someone else's transfer.
        $result = $this->asaas->findTransfersByExternalReference("payout_{$payout->id}");

        $matches = array_values(array_filter(
            $result['data'] ?? [],
            fn ($transfer) => ($transfer['externalReference'] ?? null) === "payout_{$payout->id}",
        ));

        return $matches[0] ?? null;
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

    /**
     * Park a payout the reconcile cannot resolve. This moves NO money: the tokens
     * stay reserved exactly as they were, because we still cannot tell "transfer
     * never created" from "created and paying". All it does is stop the endless
     * re-querying and give the row a status that is queryable instead of buried in
     * a log line. Nothing alerts on it yet, and there is no admin requeue path —
     * until there is, a parked payout waits on someone reading the audit log.
     */
    private function markNeedsReview(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // A webhook may have settled it between the lookup and this write.
            if (! in_array($locked->status, ['processing', 'pending'], true)) {
                return;
            }

            // Same window, quieter: a non-terminal webhook (TRANSFER_CREATED) resolves
            // by externalReference and writes the id without touching the status. Only
            // a payout we could never pin an id to belongs here — with an id, the next
            // run resolves it with getTransfer, so parking would strand it instead.
            if ($locked->asaas_transfer_id !== null) {
                return;
            }

            // Drop the streak on the way out: an operator who requeues this payout to
            // 'processing' must get a full retry budget, not re-park on the next run.
            $locked->update(['status' => 'needs_review', 'unresolved_since' => null]);

            Log::warning('Payout parked for manual review', ['payout_id' => $locked->id]);
            Audit::log('payout.needs_review', $locked);
        });
    }

    private function markPaid(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::where('id', $payout->id)->lockForUpdate()->first();

            // Accept 'pending' too: a TRANSFER_PAID webhook can race ahead of our
            // own update to 'processing' (or the process may die right after
            // createTransfer). A paid transfer must not get stranded as unpaid.
            // 'needs_review' is accepted for the same reason: parking a payout only
            // ends the reconcile's retries, it must never block a real settlement.
            if (! in_array($locked->status, ['processing', 'pending', 'needs_review'], true)) {
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
