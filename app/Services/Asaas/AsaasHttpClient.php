<?php

namespace App\Services\Asaas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function getTransfer(string $transferId): array
    {
        return $this->get("/transfers/{$transferId}");
    }

    public function findTransfersByExternalReference(string $externalReference): array
    {
        return $this->get('/transfers?externalReference=' . urlencode($externalReference));
    }

    public function createSubscription(array $data): array
    {
        // $data traz billingType=CREDIT_CARD, customer, value, cycle, nextDueDate
        // e, na primeira vez, creditCard + creditCardHolderInfo. O post() já não
        // loga o payload (PII + cartão) — ver handle().
        return $this->post('/subscriptions', $data);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->get("/subscriptions/{$subscriptionId}");
    }

    public function getSubscriptionPayments(string $subscriptionId): array
    {
        return $this->get("/subscriptions/{$subscriptionId}/payments");
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->delete("/subscriptions/{$subscriptionId}");
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
            // Definitive: a bad request we won't even send — safe to fail hard.
            throw new AsaasRequestException("Unsupported PIX key type: {$data['pix_key_type']}");
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
        try {
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->timeout(self::TIMEOUT_SECONDS)->post($this->baseUrl . $path, $data);
        } catch (ConnectionException $e) {
            // Timeout / connection reset: the request may still have been processed
            // by Asaas. Ambiguous — callers must not assume it failed.
            throw new AsaasUnavailableException("Asaas unreachable on POST {$path}: {$e->getMessage()}", previous: $e);
        }

        return $this->handle($path, $response);
    }

    private function get(string $path): array
    {
        try {
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->timeout(self::TIMEOUT_SECONDS)->get($this->baseUrl . $path);
        } catch (ConnectionException $e) {
            throw new AsaasUnavailableException("Asaas unreachable on GET {$path}: {$e->getMessage()}", previous: $e);
        }

        return $this->handle($path, $response);
    }

    private function delete(string $path): array
    {
        try {
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->timeout(self::TIMEOUT_SECONDS)->delete($this->baseUrl . $path);
        } catch (ConnectionException $e) {
            throw new AsaasUnavailableException("Asaas unreachable on DELETE {$path}: {$e->getMessage()}", previous: $e);
        }

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
            $message = "Asaas API error: HTTP {$response->status()}{$detail}";

            // 5xx = Asaas-side failure; the request may have been processed →
            // ambiguous. 4xx = rejected/not processed → definitive.
            //
            // 408 e 429 são 4xx que NÃO significam "não processado", e por isso
            // contam como ambíguos:
            //  - 408: o servidor desistiu de esperar o request, sem dizer se já
            //    tinha processado;
            //  - 429: o rate limit pode vir do Asaas (rejeitou, não criou) mas
            //    também de um proxy/WAF no meio do caminho — que responde DEPOIS
            //    do Asaas ter aceitado a transferência.
            // Classificá-los como definitivos estornava a reserva de um payout
            // que talvez já esteja pagando o PIX, ou seja: pagamento em dobro.
            // O preço de errar para "ambíguo" é só um payout que demora mais,
            // resolvido pelo webhook ou pelo payouts:reconcile.
            $ambiguous = $response->serverError()
                || in_array($response->status(), [408, 429], true);

            throw $ambiguous
                ? new AsaasUnavailableException($message)
                : new AsaasRequestException($message);
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
