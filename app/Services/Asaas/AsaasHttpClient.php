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

    // Maps our internal pix_key_type values to Asaas's pixAddressKeyType enum.
    // Note a random ("chave aleatória") key is EVP in Asaas — NOT "RANDOM", which
    // a naive strtoupper() would produce and Asaas would reject.
    private const PIX_KEY_TYPE_MAP = [
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'email' => 'EMAIL',
        'phone' => 'PHONE',
        'random' => 'EVP',
    ];

    public function createTransfer(array $data): array
    {
        $keyType = strtolower((string) $data['pix_key_type']);

        if (! isset(self::PIX_KEY_TYPE_MAP[$keyType])) {
            throw new RuntimeException("Unsupported PIX key type: {$data['pix_key_type']}");
        }

        return $this->post('/transfers', [
            'pixAddressKey' => $data['pix_key'],
            'pixAddressKeyType' => self::PIX_KEY_TYPE_MAP[$keyType],
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
