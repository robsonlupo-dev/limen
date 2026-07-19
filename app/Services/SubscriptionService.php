<?php

namespace App\Services;

use App\Exceptions\AlreadySubscribedException;
use App\Models\Circle;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\TokenLedger;
use App\Models\User;
use App\Models\WaitlistEntry;
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
     *
     * Founding Members (waitlist confirmada) ganham 7 dias grátis na PRIMEIRA
     * assinatura. O trial é feito do jeito que o Asaas documenta: adiando o
     * nextDueDate — não existe campo de trial na API de assinaturas, então
     * qualquer flag só nossa deixaria o cartão sendo cobrado no dia 0 enquanto a
     * UI promete 7 dias grátis. trial_ends_at guarda essa mesma data.
     */
    public function subscribe(User $user, Circle $circle, array $cardData): Subscription
    {
        if ($user->activeSubscription() !== null) {
            throw new AlreadySubscribedException();
        }

        $trialEndsAt = $this->trialEndsAtFor($user);

        $this->ensureAsaasCustomer($user, $cardData);

        $asaasSub = $this->asaas->createSubscription([
            'customer' => $user->asaas_customer_id,
            'billingType' => 'CREDIT_CARD',
            'value' => $circle->price_cents / 100,
            'cycle' => 'MONTHLY',
            // Primeira cobrança: hoje, ou no fim do trial para Founding Members.
            'nextDueDate' => ($trialEndsAt ?? now())->format('Y-m-d'),
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

        // Os tokens do primeiro mês entram agora, inclusive no trial. O período
        // pago começa a contar do fim do trial, senão haveria um buraco entre o
        // fim do mês concedido e a primeira renovação (que só vem 1 mês depois da
        // primeira cobrança) — e isActive() derrubaria o acesso nesses dias.
        $periodEnd = ($trialEndsAt ?? now())->copy()->addMonthNoOverflow();

        try {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'circle_id' => $circle->id,
                'asaas_subscription_id' => $asaasSubId,
                'status' => 'active',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'next_due_date' => ($trialEndsAt ?? $periodEnd)->toDateString(),
                'cancel_at_period_end' => false,
                'trial_ends_at' => $trialEndsAt,
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

        // PCI SAQ-D: record that a reusable card token was stored, for the audit
        // trail — never the token value itself (only the non-sensitive last4/brand).
        if ($subscription->card_token !== null) {
            Audit::log('card_token.stored', $subscription, [
                'card_last4' => $subscription->card_last4,
                'card_brand' => $subscription->card_brand,
            ]);
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
            'trial_ends_at' => $trialEndsAt?->toIso8601String(),
        ]);

        return $subscription->refresh();
    }

    /**
     * Fim do trial de 7 dias, ou null se este usuário não tem direito.
     *
     * Founding Member = entrada de waitlist confirmada com o email do usuário
     * (confirmed_at nulo é só um cadastro que nunca clicou no link — não conta).
     * O benefício é da PRIMEIRA assinatura: qualquer assinatura anterior, mesmo
     * cancelada, já consumiu o trial. Sem essa checagem bastaria assinar,
     * cancelar e reassinar para nunca pagar.
     */
    private function trialEndsAtFor(User $user): ?\Illuminate\Support\Carbon
    {
        if (Subscription::where('user_id', $user->id)->exists()) {
            return null;
        }

        $isFounder = WaitlistEntry::where('email', $user->email)
            ->whereNotNull('confirmed_at')
            ->exists();

        return $isFounder ? now()->addDays(7) : null;
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
            // Durante o trial este grant é o do primeiro mês, concedido no dia 0
            // enquanto a cobrança só cai em trial_ends_at: ancorar a janela em
            // now() a encerraria no dia 30 e deixaria o founder sem acesso até a
            // renovação do dia 37. O ciclo pago conta do fim do trial.
            $inTrial = $subscription->isInTrial();
            $cycleStart = $inTrial ? $subscription->trial_ends_at->copy() : now();

            $subscription->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => $cycleStart->copy()->addMonthNoOverflow(),
                // No trial a próxima cobrança é o fim do trial (ainda não pagou);
                // fora dele, a renovação do mês seguinte, como sempre foi.
                'next_due_date' => ($inTrial ? $cycleStart : $cycleStart->copy()->addMonthNoOverflow())->toDateString(),
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
            // PCI SAQ-D data minimization: once the subscription is terminated at
            // the gateway, our stored card token can never be charged again, so we
            // purge it (Asaas keeps its own copy for any dispute). last4/brand stay
            // for the member's history — they are not the reusable credential.
            $hadToken = $subscription->card_token !== null;

            $subscription->update([
                'status' => 'canceled',
                'canceled_at' => now(),
                'card_token' => null,
            ]);

            if ($hadToken) {
                Audit::log('card_token.purged', $subscription, [
                    'reason' => 'subscription_terminated',
                ]);
            }
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
