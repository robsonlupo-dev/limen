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
        private TokenCreditPolicy $creditPolicy,
    ) {}

    public function createPayment(User $user, TokenPackage $package, ?string $cpf = null): Payment
    {
        // Gate de teto (M.13.9): barra a compra que estouraria o teto ANTES de
        // criar a cobrança PIX — não se cobra por tokens que não serão creditados
        // sem o membro gastar antes. É best-effort (duas compras concorrentes
        // podem passar juntas; o webhook então credita cheio e loga — estado
        // legítimo). O teto duro é só do grant, nunca da compra paga.
        $this->creditPolicy->assertCanPurchase($user, $package->tokens);

        $this->ensureAsaasCustomer($user, $cpf);

        // Desconto do Círculo ativo incide sobre o PREÇO do pacote, nunca sobre a
        // quantidade de tokens creditada (o cliente paga menos pelos mesmos tokens).
        // A taxa vem da config (M.13.3) via policy — a fonte canônica —, não de
        // `circles.discount_pct` (que virou espelho de exibição, sincronizado por
        // migração + teste). Rewiring do Sprint 14: o código vivo respeita a
        // invariante em vez de reimplementá-la.
        $discountPct = $this->creditPolicy->purchaseDiscountPct($user);
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
        } elseif (in_array($eventType, self::REVERSAL_EVENTS)) {
            $this->handleReversal($payment, $payload);
        }

        PaymentEvent::where('provider_event_id', $eventId)->update(['processed_at' => now()]);
    }

    /**
     * Eventos do Asaas de reversão TOTAL do dinheiro. Reembolso PARCIAL tem evento
     * próprio (PAYMENT_PARTIALLY_REFUNDED, que NÃO tratamos) e chargeback apenas
     * SOLICITADO é provisório (idem) — nenhum dos dois entra aqui de propósito: um
     * clawback sobre sinal parcial/incerto seria irreversível e injusto.
     */
    private const REVERSAL_EVENTS = [
        'PAYMENT_REFUNDED',
        'PAYMENT_REVERSED',
    ];

    /**
     * Compra estornada de forma TOTAL e DEFINITIVA. Marca `refunded` (idempotente) e
     * aciona o programa de indicação: se esta compra foi a base de uma indicação, o
     * bônus é retido (antes do crédito) ou estornado (depois) — ver ReferralService.
     *
     * Defesa extra (skill asaas-pix): só age se o STATUS da cobrança no payload
     * autenticado confirmar reversão total (REFUNDED/REVERSED). Assim um
     * PAYMENT_REFUNDED de reembolso PARCIAL (status ainda RECEIVED/CONFIRMED) não
     * derruba a venda nem estorna o bônus.
     *
     * NÃO reverte os tokens já comprados: clawback de compra é decisão de produto à
     * parte. Aqui só o status do pagamento e o efeito na indicação. Contabilidade:
     * `refunded` sai da receita real (AdminMetricsService conta só `confirmed`) — o
     * que é o correto para um estorno total.
     */
    private function handleReversal(Payment $payment, array $payload): void
    {
        $remoteStatus = strtoupper((string) ($payload['payment']['status'] ?? ''));
        if (! in_array($remoteStatus, ['REFUNDED', 'REVERSED'], true)) {
            return; // não é reversão total definitiva — não mexe no pagamento nem na indicação
        }

        if ($payment->status === 'refunded') {
            return;
        }

        $previous = $payment->status;
        $payment->update(['status' => 'refunded']);

        Audit::log('payment.reversed', $payment, ['previous_status' => $previous]);

        // Erro na indicação NUNCA pode quebrar o processamento do webhook de reversão
        // (o service já engole os próprios erros; este try/catch é cinto e suspensório).
        try {
            app(ReferralService::class)->onMemberPurchaseReversed($payment->user);
        } catch (\Throwable $e) {
            Log::warning('referral.reversal_hook_failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
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
                // Webhook = dinheiro pago: credita SEMPRE, mesmo acima do teto
                // (M.13.9). A idempotência por (reference_type,reference_id) acima
                // é o que impede duplicar; a policy só decide "credita cheio + loga
                // se passou do teto". A dedup fica FORA e ANTES da policy.
                $this->creditPolicy->creditPaidPurchase(
                    $payment->user,
                    $payment->tokens,
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

        // Programa de indicação: a 1ª compra confirmada do membro indicado qualifica
        // a indicação (3.A). FORA da transação de crédito e com erro engolido — uma
        // falha aqui NUNCA pode desfazer/derrubar a confirmação do pagamento.
        // Idempotente: só age numa indicação `pending`, então rodar de novo (webhook
        // reenviado, reconcile) não duplica.
        app(ReferralService::class)->onReferredMemberPurchase($payment->user);
    }

    public function reconcile(): void
    {
        $pendingPayments = Payment::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->get();

        // Ids sintéticos ('pay_fake_...') vêm do FakeAsaasClient (seed/dev/staging).
        // Só o gateway REAL devolve 404 neles; a Fake os resolve normalmente. Por
        // isso a faxina só vale contra o gateway real — senão o reconcile de
        // dev/staging pararia de processar a própria massa sintética (e quebraria
        // os testes de reconcile, que rodam sobre a Fake).
        $realGateway = ! $this->asaas->isFake();

        foreach ($pendingPayments as $payment) {
            // Pagamento de seed/teste alcançando o Asaas REAL: consultá-lo dá 404,
            // e o reconcile roda de tempos em tempos — Log::error recorrente sobre
            // dado sintético. Pula sem consultar; info, não error.
            if ($realGateway && str_starts_with((string) $payment->provider_charge_id, 'pay_fake_')) {
                Log::info('Skipping fake payment', [
                    'payment_id' => $payment->id,
                    'provider_charge_id' => $payment->provider_charge_id,
                ]);

                continue;
            }

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
