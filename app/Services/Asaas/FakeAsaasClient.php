<?php

namespace App\Services\Asaas;

class FakeAsaasClient implements AsaasClientInterface
{
    private array $charges = [];

    private array $customers = [];

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
}
