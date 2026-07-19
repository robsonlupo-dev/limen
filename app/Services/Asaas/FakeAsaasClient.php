<?php

namespace App\Services\Asaas;

class FakeAsaasClient implements AsaasClientInterface
{
    private array $charges = [];

    private array $customers = [];

    private array $transfers = [];

    private array $subscriptions = [];

    /** Payloads crus de createSubscription, na ordem — para os testes conferirem
     *  o que foi enviado ao gateway (ex.: o nextDueDate adiado do trial). */
    private array $subscriptionPayloads = [];

    private bool $forceNextTransferFailure = false;

    private bool $forceNextTransferUnavailable = false;

    private bool $forceNextGetTransferFailure = false;

    private bool $forceNextSubscriptionCancelFailure = false;

    public function createCustomer(array $data): array
    {
        $customer = [
            'id' => 'cus_fake_' . uniqid(),
            'name' => $data['name'] ?? 'Fake Customer',
            'email' => $data['email'] ?? 'fake@test.com',
            'cpfCnpj' => $data['cpfCnpj'] ?? '',
        ];

        $this->customers[] = $customer;

        return $customer;
    }

    public function getCreatedCustomers(): array
    {
        return $this->customers;
    }

    public function createPixCharge(array $data): array
    {
        $id = 'pay_fake_' . uniqid();

        $this->charges[$id] = [
            'id' => $id,
            'status' => 'PENDING',
            'value' => $data['value'],
            'billingType' => 'PIX',
            'externalReference' => $data['externalReference'] ?? null,
            'dueDate' => $data['dueDate'] ?? now()->addDay()->format('Y-m-d'),
        ];

        return $this->charges[$id];
    }

    public function getPixQrCode(string $chargeId): array
    {
        return [
            'encodedImage' => base64_encode('fake-qr-image-' . $chargeId),
            'payload' => 'fake-pix-copy-paste-' . $chargeId,
        ];
    }

    public function getPayment(string $chargeId): array
    {
        if (isset($this->charges[$chargeId])) {
            return $this->charges[$chargeId];
        }

        return [
            'id' => $chargeId,
            'status' => 'PENDING',
            'value' => 0,
            'billingType' => 'PIX',
        ];
    }

    public function simulatePaymentReceived(string $chargeId): void
    {
        if (isset($this->charges[$chargeId])) {
            $this->charges[$chargeId]['status'] = 'RECEIVED';
        }
    }

    public function simulatePaymentOverdue(string $chargeId): void
    {
        if (isset($this->charges[$chargeId])) {
            $this->charges[$chargeId]['status'] = 'OVERDUE';
        }
    }

    public function createTransfer(array $data): array
    {
        if ($this->forceNextTransferUnavailable) {
            $this->forceNextTransferUnavailable = false;
            // Ambiguous outcome: still record the transfer (as if Asaas created it
            // but our response was lost), so reconcile can later find it.
            $id = 'transfer_fake_' . uniqid();
            $this->transfers[$id] = [
                'id' => $id,
                'status' => 'PENDING',
                'value' => $data['value'],
                'externalReference' => $data['external_reference'] ?? null,
            ];

            throw new AsaasUnavailableException('Simulated Asaas transfer timeout.');
        }

        if ($this->forceNextTransferFailure) {
            $this->forceNextTransferFailure = false;
            throw new AsaasRequestException('Simulated Asaas transfer rejection.');
        }

        $id = 'transfer_fake_' . uniqid();

        $this->transfers[$id] = [
            'id' => $id,
            'status' => 'PENDING',
            'value' => $data['value'],
            'pixAddressKey' => $data['pix_key'] ?? null,
            'pixAddressKeyType' => $data['pix_key_type'] ?? null,
            'description' => $data['description'] ?? null,
            'externalReference' => $data['external_reference'] ?? null,
        ];

        return $this->transfers[$id];
    }

    public function getTransfer(string $transferId): array
    {
        if ($this->forceNextGetTransferFailure) {
            $this->forceNextGetTransferFailure = false;
            // e.g. um 429 no meio de um batch de reconcile — soluço operacional.
            // Ambígua, e não definitiva: é assim que o AsaasHttpClient real
            // classifica um 429 (ver handle()). O fake precisa espelhar o real —
            // um fake que classifica errado esconde justamente o bug que ele
            // deveria pegar, como já aconteceu com o random→EVP.
            // (401 continua definitivo no real; o ramo definitivo aqui é o 404.)
            throw new AsaasUnavailableException('Asaas API error: HTTP 429 (rate limited)');
        }

        if (isset($this->transfers[$transferId])) {
            return $this->transfers[$transferId];
        }

        throw new AsaasRequestException('Asaas API error: HTTP 404 (transfer not found)');
    }

    public function findTransfersByExternalReference(string $externalReference): array
    {
        $matches = array_values(array_filter(
            $this->transfers,
            fn ($transfer) => ($transfer['externalReference'] ?? null) === $externalReference,
        ));

        return ['data' => $matches];
    }

    public function forceNextTransferFailure(): void
    {
        $this->forceNextTransferFailure = true;
    }

    public function forceNextTransferUnavailable(): void
    {
        $this->forceNextTransferUnavailable = true;
    }

    public function forceNextGetTransferFailure(): void
    {
        $this->forceNextGetTransferFailure = true;
    }

    public function simulateTransferPaid(string $transferId): void
    {
        if (isset($this->transfers[$transferId])) {
            $this->transfers[$transferId]['status'] = 'DONE';
        }
    }

    public function simulateTransferFailed(string $transferId): void
    {
        if (isset($this->transfers[$transferId])) {
            $this->transfers[$transferId]['status'] = 'FAILED';
        }
    }

    // ── Subscriptions ────────────────────────────────────────────────────────

    public function createSubscription(array $data): array
    {
        $id = 'sub_fake_' . uniqid();

        // Guarda o payload SEM os campos sensíveis do cartão: os testes só aferem
        // termos de cobrança (nextDueDate, value, cycle) e não há razão para PAN e
        // CCV ficarem parados em memória, nem para vazarem num dump de falha.
        $recorded = $data;
        unset($recorded['creditCard']['number'], $recorded['creditCard']['ccv'], $recorded['creditCardHolderInfo']);
        $this->subscriptionPayloads[] = $recorded;

        $card = $data['creditCard'] ?? [];
        $number = (string) ($card['number'] ?? '');
        $last4 = $number !== '' ? substr($number, -4) : null;

        // O primeiro charge nasce junto da assinatura. Devolvemos o id dele para
        // que o SubscriptionService ancore o grant inicial nesse charge — o mesmo
        // id que o webhook do primeiro pagamento vai trazer, fechando a dedupe.
        $firstPaymentId = 'pay_fake_' . uniqid();

        $this->subscriptions[$id] = [
            'id' => $id,
            'status' => 'ACTIVE',
            'value' => $data['value'] ?? 0,
            'cycle' => $data['cycle'] ?? 'MONTHLY',
            'nextDueDate' => $data['nextDueDate'] ?? now()->format('Y-m-d'),
            'externalReference' => $data['externalReference'] ?? null,
            'payments' => [$firstPaymentId],
        ];

        // O primeiro charge é uma cobrança confirmada como qualquer outra — fica
        // visível por getPayment(), como no Asaas real, para o serviço reconferir.
        $this->charges[$firstPaymentId] = [
            'id' => $firstPaymentId,
            'status' => 'CONFIRMED',
            'value' => $data['value'] ?? 0,
            'billingType' => 'CREDIT_CARD',
            'subscription' => $id,
        ];

        return [
            'id' => $id,
            'status' => 'ACTIVE',
            'value' => $data['value'] ?? 0,
            'cycle' => $data['cycle'] ?? 'MONTHLY',
            'nextDueDate' => $data['nextDueDate'] ?? now()->format('Y-m-d'),
            // NOTA: o Asaas real NÃO devolve o id do primeiro pagamento no create.
            // O serviço não depende disto — busca via getSubscriptionPayments().
            'creditCard' => [
                'creditCardNumber' => $last4,
                'creditCardBrand' => $card['brand'] ?? 'VISA',
                'creditCardToken' => 'cctok_fake_' . uniqid(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getCreatedSubscriptionPayloads(): array
    {
        return $this->subscriptionPayloads;
    }

    public function getSubscription(string $subscriptionId): array
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            return $this->subscriptions[$subscriptionId];
        }

        throw new AsaasRequestException('Asaas API error: HTTP 404 (subscription not found)');
    }

    public function getSubscriptionPayments(string $subscriptionId): array
    {
        $ids = $this->subscriptions[$subscriptionId]['payments'] ?? [];

        return [
            'data' => array_map(
                fn (string $paymentId) => $this->charges[$paymentId]
                    ?? ['id' => $paymentId, 'status' => 'CONFIRMED', 'subscription' => $subscriptionId],
                $ids,
            ),
        ];
    }

    /** Faz o próximo cancelamento de assinatura falhar (gateway fora do ar). */
    public function forceNextSubscriptionCancelFailure(): void
    {
        $this->forceNextSubscriptionCancelFailure = true;
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        if ($this->forceNextSubscriptionCancelFailure) {
            $this->forceNextSubscriptionCancelFailure = false;

            throw new AsaasUnavailableException('Simulated Asaas subscription cancel failure.');
        }

        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['status'] = 'INACTIVE';
        }

        return ['id' => $subscriptionId, 'deleted' => true];
    }

    /**
     * Build the webhook payload for a renewal charge being confirmed on a
     * subscription, as Asaas would POST it. Returns the payload so the test can
     * feed it to SubscriptionService::handleWebhook.
     */
    public function simulateSubscriptionCharged(string $subscriptionId): array
    {
        $chargeId = 'pay_fake_' . uniqid();
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['payments'][] = $chargeId;
        }

        // Registra a cobrança como confirmada, para que a reconferência por
        // getPayment() no serviço enxergue o mesmo que o Asaas real enxergaria.
        $this->charges[$chargeId] = [
            'id' => $chargeId,
            'status' => 'CONFIRMED',
            'value' => $this->subscriptions[$subscriptionId]['value'] ?? 0,
            'billingType' => 'CREDIT_CARD',
            'subscription' => $subscriptionId,
        ];

        return [
            'event' => 'PAYMENT_CONFIRMED',
            'id' => 'evt_fake_' . uniqid(),
            'payment' => [
                'id' => $chargeId,
                'subscription' => $subscriptionId,
                'value' => $this->subscriptions[$subscriptionId]['value'] ?? 0,
                'status' => 'CONFIRMED',
            ],
        ];
    }

    public function simulateSubscriptionOverdue(string $subscriptionId): array
    {
        $chargeId = $this->subscriptions[$subscriptionId]['payments'][0] ?? ('pay_fake_' . uniqid());

        return [
            'event' => 'PAYMENT_OVERDUE',
            'id' => 'evt_fake_' . uniqid(),
            'payment' => [
                'id' => $chargeId,
                'subscription' => $subscriptionId,
                'status' => 'OVERDUE',
            ],
        ];
    }

    public function simulateSubscriptionCanceled(string $subscriptionId): array
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['status'] = 'INACTIVE';
        }

        return [
            'event' => 'SUBSCRIPTION_DELETED',
            'id' => 'evt_fake_' . uniqid(),
            'subscription' => [
                'id' => $subscriptionId,
                'status' => 'INACTIVE',
            ],
        ];
    }
}
