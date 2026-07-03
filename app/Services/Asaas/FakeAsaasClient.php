<?php

namespace App\Services\Asaas;

class FakeAsaasClient implements AsaasClientInterface
{
    private array $charges = [];

    private array $customers = [];

    private array $transfers = [];

    private bool $forceNextTransferFailure = false;

    private bool $forceNextTransferUnavailable = false;

    private bool $forceNextGetTransferFailure = false;

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
            // e.g. a 429/401 during a reconcile batch — an operational hiccup.
            throw new AsaasRequestException('Asaas API error: HTTP 429 (rate limited)');
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
}
