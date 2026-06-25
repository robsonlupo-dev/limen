<?php

namespace App\Services\Asaas;

interface AsaasClientInterface
{
    public function createCustomer(array $data): array;

    public function createPixCharge(array $data): array;

    public function getPixQrCode(string $chargeId): array;

    public function getPayment(string $chargeId): array;
}
