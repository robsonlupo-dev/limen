<?php

namespace App\Services\Kyc;

use Illuminate\Support\Facades\Http;

/**
 * Real KYC provider: Didit (https://docs.didit.me).
 *
 * Auth is OAuth2 client_credentials against the Keycloak realm on auth.didit.me;
 * verification sessions live on apx.didit.me. The performer is redirected to the
 * session `url`; Didit posts the decision back to our webhook (callback_url) and
 * we can also poll GET /v2/session/{id}/decision/.
 */
class DiditKycClient implements KycClientInterface
{
    // Cached per-instance so the two calls in one request reuse a single token.
    // The container binds this as a singleton, but we do not persist across
    // requests: an expired token would then 401 every call until redeploy.
    private ?string $accessToken = null;

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = Http::asForm()->post(
            rtrim((string) config('kyc.auth_url'), '/')
                . '/auth/realms/didit-essentials/protocol/openid-connect/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => config('kyc.client_id'),
                'client_secret' => config('kyc.client_secret'),
            ],
        );

        $response->throw();

        return $this->accessToken = (string) $response->json('access_token');
    }

    public function submitVerification(array $data): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'x-client-id' => config('kyc.client_id'),
        ])->post(rtrim((string) config('kyc.base_url'), '/') . '/v2/session/', [
            'workflow_id' => config('kyc.workflow_id'),
            'redirect_url' => config('app.url') . '/kyc/callback',
            'callback_url' => config('app.url') . '/api/v1/webhooks/kyc',
            'vendor_data' => (string) ($data['vendor_data'] ?? $data['performer_id'] ?? ''),
        ]);

        $response->throw();

        return [
            'reference' => $response->json('session_id'),
            'status' => 'pending',
            'url' => $response->json('url') ?? $response->json('verification_url'),
        ];
    }

    public function getVerification(string $ref): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'x-client-id' => config('kyc.client_id'),
        ])->get(rtrim((string) config('kyc.base_url'), '/') . '/v2/session/' . $ref . '/decision/');

        $response->throw();

        return [
            'reference' => $ref,
            'status' => $this->mapStatus($response->json('status')),
        ];
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
