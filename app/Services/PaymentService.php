<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private AsaasClientInterface $asaas,
        private TokenService $tokenService,
    ) {}

    public function createPayment(User $user, TokenPackage $package, ?string $cpf = null): Payment
    {
        $this->ensureAsaasCustomer($user, $cpf);

        // Desconto do Círculo ativo incide sobre o PREÇO do pacote, nunca sobre a
        // quantidade de tokens creditada (o cliente paga menos pelos mesmos tokens).
        $discountPct = $user->activeCircle()?->discount_pct ?? 0;
        $amountCents = (int) round($package->price_cents * (100 - $discountPct) / 100);

        $payload = [
            'customer' => $user->asaas_customer_id,
            'billingType' => 'PIX',
            'value' => $amountCents / 100,
            'dueDate' => now()->addDay()->format('Y-m-d'),
            'externalReference' => "user_{$user->id}_pkg_{$package->id}",
        ];

        $charge = $this->asaas->createPixCharge($payload);

        $qr = $this->asaas->getPixQrCode($charge['id']);

        $payment = Payment::create([
            'user_id' => $user->id,
            'token_package_id' => $package->id,
            'provider' => 'asaas',
            'provider_charge_id' => $charge['id'],
            'method' => 'pix',
            'amount_cents' => $amountCents,
            'tokens' => $package->tokens,
            'status' => 'pending',
            'pix_qr_code' => $qr['encodedImage'],
            'pix_copy_paste' => $qr['payload'],
            'expires_at' => now()->addDay(),
        ]);

        Audit::log('payment.created', $payment, [
            'tokens' => $package->tokens,
            'amount_cents' => $amountCents,
            'discount_pct' => $discountPct,
        ]);

        return $payment;
    }

    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['event'] ?? null;
        $chargeId = $payload['payment']['id'] ?? null;

        if (! $eventType || ! $chargeId) {
            return;
        }

        $eventId = $payload['id'] ?? "{$eventType}_{$chargeId}";

        $alreadyProcessed = PaymentEvent::where('provider_event_id', $eventId)->exists();
        if ($alreadyProcessed) {
            return;
        }

        $payment = Payment::where('provider_charge_id', $chargeId)->first();

        PaymentEvent::create([
            'provider' => 'asaas',
            'provider_event_id' => $eventId,
            'payment_id' => $payment?->id,
            'payload' => $payload,
        ]);

        if (! $payment) {
            Log::warning('Webhook for unknown charge', ['charge_id' => $chargeId, 'event' => $eventId]);
            return;
        }

        if (in_array($eventType, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
            try {
                $this->confirmPayment($payment);
            } catch (\Throwable $e) {
                // confirmPayment re-queries Asaas (getPayment), which can fail or
                // time out against the live gateway. Leave processed_at null so the
                // event stays visibly unprocessed and payments:reconcile retries the
                // credit — better a delayed credit than a silently swallowed one.
                Log::error('Webhook confirm failed; reconcile will retry', [
                    'payment_id' => $payment->id,
                    'event' => $eventId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        } elseif ($eventType === 'PAYMENT_OVERDUE') {
            $payment->update(['status' => 'expired']);
        }

        PaymentEvent::where('provider_event_id', $eventId)->update(['processed_at' => now()]);
    }

    public function confirmPayment(Payment $payment): void
    {
        if (in_array($payment->status, ['confirmed', 'expired', 'failed', 'refunded'])) {
            return;
        }

        $remoteCharge = $this->asaas->getPayment($payment->provider_charge_id);

        if (! in_array($remoteCharge['status'] ?? '', ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'])) {
            return;
        }

        DB::transaction(function () use ($payment) {
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->first();

            if ($payment->status !== 'pending') {
                return;
            }

            $alreadyCredited = TokenLedger::where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->exists();

            if (! $alreadyCredited) {
                $this->tokenService->credit(
                    $payment->user,
                    $payment->tokens,
                    'purchase',
                    'payment',
                    $payment->id,
                    "Purchase: {$payment->tokens} tokens",
                );
            }

            $payment->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            Audit::log('payment.confirmed', $payment, [
                'tokens' => $payment->tokens,
                'amount_cents' => $payment->amount_cents,
            ]);
        });
    }

    public function reconcile(): void
    {
        $pendingPayments = Payment::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->get();

        foreach ($pendingPayments as $payment) {
            try {
                $remote = $this->asaas->getPayment($payment->provider_charge_id);
                $status = $remote['status'] ?? '';

                if (in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'])) {
                    $this->confirmPayment($payment);
                } elseif (in_array($status, ['OVERDUE', 'REFUNDED', 'DELETED'])) {
                    $payment->update(['status' => 'expired']);
                } elseif ($payment->expires_at && $payment->expires_at->isPast()) {
                    $payment->update(['status' => 'expired']);
                }
            } catch (\Throwable $e) {
                Log::error('Reconcile error', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function ensureAsaasCustomer(User $user, ?string $cpf = null): void
    {
        if ($user->asaas_customer_id) {
            return;
        }

        $customer = $this->asaas->createCustomer([
            'name' => $user->name,
            'email' => $user->email,
            'cpfCnpj' => preg_replace('/\D/', '', $cpf ?? ''),
        ]);

        $user->asaas_customer_id = $customer['id'];
        $user->save();
        $user->refresh();
    }
}
