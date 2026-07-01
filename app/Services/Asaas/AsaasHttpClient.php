<?php

namespace App\Services\Asaas;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AsaasHttpClient implements AsaasClientInterface
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('asaas.base_url'), '/');
        $this->apiKey = config('asaas.api_key');
    }

    public function createCustomer(array $data): array
    {
        return $this->post('/customers', $data);
    }

    public function createPixCharge(array $data): array
    {
        return $this->post('/payments', $data);
    }

    public function getPixQrCode(string $chargeId): array
    {
        return $this->get("/payments/{$chargeId}/pixQrCode");
    }

    public function getPayment(string $chargeId): array
    {
        return $this->get("/payments/{$chargeId}");
    }

    public function createTransfer(array $data): array
    {
        return $this->post('/transfers', [
            'pixAddressKey' => $data['pix_key'],
            'pixAddressKeyType' => strtoupper($data['pix_key_type']),
            'value' => $data['value'],
            'description' => $data['description'] ?? null,
            'externalReference' => $data['external_reference'] ?? null,
        ]);
    }

    private function post(string $path, array $data): array
    {
        $response = Http::withHeaders([
            'access_token' => $this->apiKey,
        ])->post($this->baseUrl . $path, $data);

        if ($response->failed()) {
            throw new RuntimeException("Asaas API error: HTTP {$response->status()}");
        }

        return $response->json();
    }

    private function get(string $path): array
    {
        $response = Http::withHeaders([
            'access_token' => $this->apiKey,
        ])->get($this->baseUrl . $path);

        if ($response->failed()) {
            throw new RuntimeException("Asaas API error: HTTP {$response->status()}");
        }

        return $response->json();
    }
}
