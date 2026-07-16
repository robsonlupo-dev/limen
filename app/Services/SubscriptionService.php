<?php

namespace App\Services;

use App\Exceptions\AlreadySubscribedException;
use App\Models\Circle;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    public function __construct(
        private AsaasClientInterface $asaas,
        private TokenService $tokenService,
    ) {}

    /**
     * Subscribe a user to a Círculo with a credit card. The raw card is sent to
     * Asaas once (tokenized there); we persist only the reusable token + last4 +
     * brand — never the PAN. The first month's tokens are granted immediately,
     * anchored on the first charge id so the first renewal webhook can't
     * double-grant it.
     */
    public function subscribe(User $user, Circle $circle, array $cardData): Subscription
    {
        if ($user->activeSubscription() !== null) {
            throw new AlreadySubscribedException();
        }

        $this->ensureAsaasCustomer($user, $cardData);

        $asaasSub = $this->asaas->createSubscription([
            'customer' => $user->asaas_customer_id,
            'billingType' => 'CREDIT_CARD',
            'value' => $circle->price_cents / 100,
            'cycle' => 'MONTHLY',
            'nextDueDate' => now()->format('Y-m-d'),
            'externalReference' => "user_{$user->id}_circle_{$circle->id}",
            'creditCard' => [
                'holderName' => $cardData['holderName'] ?? null,
                'number' => $cardData['number'] ?? null,
                'expiryMonth' => $cardData['expiryMonth'] ?? null,
                'expiryYear' => $cardData['expiryYear'] ?? null,
                'ccv' => $cardData['ccv'] ?? null,
            ],
            'creditCardHolderInfo' => $cardData['holder'] ?? [],
        ]);

        $card = $asaasSub['creditCard'] ?? [];
        $asaasSubId = $asaasSub['id'] ?? null;
        $periodStart = now();
        $periodEnd = now()->addMonthNoOverflow();

        try {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'circle_id' => $circle->id,
                'asaas_subscription_id' => $asaasSubId,
                'status' => 'active',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'next_due_date' => $periodEnd->toDateString(),
                'cancel_at_period_end' => false,
                'price_cents' => $circle->price_cents,
                'card_token' => $card['creditCardToken'] ?? null,
                'card_last4' => $card['creditCardNumber'] ?? null,
                'card_brand' => $card['creditCardBrand'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // O cartão já foi cobrado no Asaas, mas o registro local falhou (ex.:
            // corrida no active_lock único). Cancela a assinatura no Asaas para
            // não deixar uma cobrança recorrente órfã.
            if ($asaasSubId) {
                try {
                    $this->asaas->cancelSubscription($asaasSubId);
                } catch (\Throwable $cancelError) {
                    Log::error('Failed to cancel orphaned Asaas subscription', [
                        'asaas_subscription_id' => $asaasSubId,
                        'error' => $cancelError->getMessage(),
                    ]);
                }
            }

            throw $e;
        }

        // Grant the first month anchored on the REAL first charge id (from Asaas,
        // not the create response — which doesn't carry it in production). The
        // first renewal webhook carries the same id, so it dedupes there. If we
        // can't resolve it, skip the grant now and let the webhook grant it.
        $firstChargeId = $this->resolveFirstChargeId($asaasSubId);
        if ($firstChargeId !== null) {
            $this->recordChargeAndGrant($subscription, $firstChargeId, $circle->price_cents);
        }

        Audit::log('subscription.created', $subscription, [
            'circle' => $circle->slug,
            'price_cents' => $circle->price_cents,
            'granted_first_month' => $firstChargeId !== null,
        ]);

        return $subscription->refresh();
    }

    /** The id of the subscription's first Asaas charge, or null if unavailable. */
    private function resolveFirstChargeId(?string $asaasSubId): ?string
    {
        if (! $asaasSubId) {
            return null;
        }

        try {
            $payments = $this->asaas->getSubscriptionPayments($asaasSubId);
        } catch (\Throwable $e) {
            Log::warning('Could not resolve first subscription charge; webhook will grant', [
                'asaas_subscription_id' => $asaasSubId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $payments['data'][0]['id'] ?? null;
    }

    /** Flag the subscription to stop at the end of the current paid period. */
    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update(['cancel_at_period_end' => true]);

        Audit::log('subscription.cancel_requested', $subscription, [
            'period_end' => $subscription->current_period_end?->toIso8601String(),
        ]);

        return $subscription;
    }

    /**
     * Handle an Asaas webhook for a subscription. Idempotent per charge via
     * subscription_charges.provider_event_id.
     */
    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['event'] ?? null;

        if (in_array($eventType, ['SUBSCRIPTION_DELETED', 'SUBSCRIPTION_INACTIVATED'], true)) {
            $this->markCanceled($payload['subscription']['id'] ?? null);
            return;
        }

        $subId = $payload['payment']['subscription'] ?? null;
        $chargeId = $payload['payment']['id'] ?? null;

        // Not a subscription payment (e.g. a one-off token purchase) — ignore.
        if (! $subId || ! $chargeId) {
            return;
        }

        $subscription = Subscription::where('asaas_subscription_id', $subId)->first();

        if (! $subscription) {
            Log::warning('Subscription webhook for unknown subscription', ['subscription' => $subId]);
            return;
        }

        if (in_array($eventType, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true)) {
            // Nunca cunhar tokens a partir do corpo do webhook: reconsulta o Asaas
            // e só concede se a cobrança estiver de fato confirmada. Espelha o
            // PaymentService::confirmPayment — defesa caso o token do webhook vaze.
            try {
                $remote = $this->asaas->getPayment($chargeId);
            } catch (\Throwable $e) {
                // Gateway indisponível: deixa estourar para o Asaas reenviar o
                // webhook (5xx). A idempotência por charge garante grant único.
                Log::error('Subscription webhook verify failed; will retry', [
                    'subscription' => $subId,
                    'charge' => $chargeId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            if (! in_array($remote['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                return;
            }

            $this->recordChargeAndGrant($subscription, $chargeId, $subscription->price_cents);
        } elseif ($eventType === 'PAYMENT_OVERDUE') {
            $subscription->update(['status' => 'past_due']);
        }
    }

    /**
     * Idempotently record a confirmed charge and grant that period's tokens.
     * Creating the subscription_charges row (unique provider_event_id) is the
     * gate: only a freshly created row grants tokens and advances the period, so
     * a replayed webhook (or CONFIRMED+RECEIVED for one charge) never
     * double-grants.
     */
    private function recordChargeAndGrant(Subscription $subscription, string $chargeKey, int $amountCents): void
    {
        DB::transaction(function () use ($subscription, $chargeKey, $amountCents) {
            $charge = SubscriptionCharge::firstOrCreate(
                ['provider_event_id' => $chargeKey],
                [
                    'subscription_id' => $subscription->id,
                    'amount_cents' => $amountCents,
                    'status' => 'confirmed',
                    'charged_at' => now(),
                ],
            );

            if (! $charge->wasRecentlyCreated) {
                return; // already granted for this charge/period
            }

            // Se esta assinatura lapsou (past_due/expired) e o usuário já migrou
            // para outra ativa, uma cobrança atrasada não pode ressuscitá-la:
            // reativar colidiria no active_lock único. Marca a cobrança como
            // superseded, sem conceder nem reativar.
            if ($subscription->status !== 'active') {
                $hasOtherActive = Subscription::where('user_id', $subscription->user_id)
                    ->where('id', '!=', $subscription->id)
                    ->where('status', 'active')
                    ->exists();

                if ($hasOtherActive) {
                    $charge->update(['status' => 'superseded', 'processed_at' => now()]);
                    return;
                }
            }

            $tokens = $subscription->circle->monthly_tokens;

            $this->tokenService->credit(
                $subscription->user,
                $tokens,
                'subscription_grant',
                'subscription_charge',
                $charge->id,
                "Círculo {$subscription->circle->slug}: {$tokens} tokens/mês",
            );

            // Keep the paid window current and recover from past_due on renewal.
            $subscription->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonthNoOverflow(),
                'next_due_date' => now()->addMonthNoOverflow()->toDateString(),
            ]);

            $charge->update(['processed_at' => now()]);
        });
    }

    private function markCanceled(?string $asaasSubId): void
    {
        if (! $asaasSubId) {
            return;
        }

        $subscription = Subscription::where('asaas_subscription_id', $asaasSubId)->first();

        if ($subscription && $subscription->status !== 'canceled') {
            $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);
        }
    }

    private function ensureAsaasCustomer(User $user, array $cardData): void
    {
        if ($user->asaas_customer_id) {
            return;
        }

        $holder = $cardData['holder'] ?? [];

        $customer = $this->asaas->createCustomer([
            'name' => $holder['name'] ?? $user->name,
            'email' => $holder['email'] ?? $user->email,
            'cpfCnpj' => preg_replace('/\D/', '', (string) ($holder['cpfCnpj'] ?? '')),
        ]);

        $user->asaas_customer_id = $customer['id'];
        $user->save();
        $user->refresh();
    }
}
