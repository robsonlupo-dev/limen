<?php

namespace App\Services\Kyc;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real KYC provider: Didit (https://docs.didit.me).
 *
 * Auth is a static API key sent as the `x-api-key` header — no OAuth2. Sessions
 * live on the verification host (config kyc.base_url). The performer is
 * redirected to the session `url`; Didit posts the decision back to our webhook
 * (callback) and we can also poll GET /v3/session/{id}/decision/.
 */
class DiditKycClient implements KycClientInterface
{
    public function submitVerification(array $data): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('kyc.api_key'),
        ])->asJson()->post(rtrim((string) config('kyc.base_url'), '/') . '/v3/session/', [
            'workflow_id' => config('kyc.workflow_id'),
            'vendor_data' => (string) ($data['vendor_data'] ?? ''),
            'callback' => config('app.url') . '/api/v1/webhooks/kyc',
        ]);

        $this->ensureOk($response, 'session');

        return [
            'reference' => $response->json('session_id'),
            'status' => 'pending',
            'url' => $response->json('url') ?? $response->json('verification_url'),
        ];
    }

    public function getVerification(string $ref): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('kyc.api_key'),
        ])->get(rtrim((string) config('kyc.base_url'), '/') . '/v3/session/' . rawurlencode($ref) . '/decision/');

        $this->ensureOk($response, 'decision');

        return [
            'reference' => $ref,
            'status' => $this->mapStatus($response->json('status')),
        ];
    }

    /**
     * Fails loudly on a non-2xx response without ever surfacing the body. Didit
     * responses carry PII (the decision endpoint) and the API key, so — unlike
     * Response::throw() — we log the status code only and raise a body-free
     * exception, honoring "PII/segredos nunca em log".
     */
    private function ensureOk(Response $response, string $context): void
    {
        if ($response->failed()) {
            Log::warning('didit request failed', [
                'context' => $context,
                'status' => $response->status(),
            ]);

            throw new RuntimeException("Didit {$context} request failed with status {$response->status()}.");
        }
    }

    /**
     * Maps Didit's decision status to our internal vocabulary. Anything that is
     * not a terminal Approved/Declined stays 'pending' (In Review, Not Started…).
     */
    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'Approved' => 'approved',
            'Declined' => 'rejected',
            default => 'pending',
        };
    }
}
