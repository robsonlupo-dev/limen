<?php

namespace App\Services\Asaas;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AsaasHttpClient implements AsaasClientInterface
{
    // Seconds to wait on the Asaas API before giving up. A hung gateway must not
    // hold a user request (or webhook handler) open indefinitely.
    private const TIMEOUT_SECONDS = 20;

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
        ])->timeout(self::TIMEOUT_SECONDS)->post($this->baseUrl . $path, $data);

        return $this->handle($path, $response);
    }

    private function get(string $path): array
    {
        $response = Http::withHeaders([
            'access_token' => $this->apiKey,
        ])->timeout(self::TIMEOUT_SECONDS)->get($this->baseUrl . $path);

        return $this->handle($path, $response);
    }

    private function handle(string $path, Response $response): array
    {
        if ($response->failed()) {
            // Asaas rejects with { "errors": [ { "code", "description" } ] }.
            // Surface that so a failing sandbox call is diagnosable — never log
            // the request payload, which carries PII (name, email, CPF).
            $errors = $this->extractErrors($response);

            Log::error('Asaas API error', [
                'path' => $path,
                'status' => $response->status(),
                'errors' => $errors,
            ]);

            $detail = $errors !== '' ? " ({$errors})" : '';

            throw new RuntimeException("Asaas API error: HTTP {$response->status()}{$detail}");
        }

        return $response->json();
    }

    private function extractErrors(Response $response): string
    {
        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return '';
        }

        return collect($errors)
            ->map(fn ($error) => $error['description'] ?? $error['code'] ?? null)
            ->filter()
            ->implode('; ');
    }
}
