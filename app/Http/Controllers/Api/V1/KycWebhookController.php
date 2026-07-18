<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\KycService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KycWebhookController extends Controller
{
    public function __construct(private KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            Log::warning('KYC webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('kyc.webhook_auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Didit posts `session_id`; the generic/fake flow posts `reference`.
        $providerReference = $request->input('session_id') ?? $request->input('reference');
        $status = $this->normalizeStatus($request->input('status'));
        $reason = $request->input('reason');

        if (! $providerReference) {
            return response()->json(['message' => 'OK.']);
        }

        $verification = IdentityVerification::where('provider_reference', $providerReference)->first();

        if (! $verification) {
            return response()->json(['message' => 'OK.']);
        }

        // Once in a terminal state, never transition again (blocks downgrade + replay)
        if (in_array($verification->status, ['approved', 'rejected'])) {
            return response()->json(['message' => 'OK.']);
        }

        if ($status === 'approved') {
            $this->kycService->approve($verification);
        } elseif ($status === 'rejected') {
            $this->kycService->reject($verification, $reason);
        }

        // Non-terminal statuses (In Review, …) are acknowledged and ignored.
        return response()->json(['message' => 'OK.']);
    }

    /**
     * Authenticates the webhook against the shared secret. When a secret is
     * configured we accept Didit's HMAC-SHA256 signature (x-signature over the
     * raw body); absent that header we fall back to the legacy shared-secret
     * header (X-Kyc-Secret). With no secret configured the endpoint is closed.
     */
    private function isAuthorized(Request $request): bool
    {
        $secret = (string) config('kyc.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $signature = $request->header('x-signature');

        if ($signature !== null) {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($expected, (string) $signature);
        }

        return hash_equals($secret, (string) $request->header('X-Kyc-Secret'));
    }

    /**
     * Normalizes provider status to our vocabulary. Accepts Didit's capitalized
     * values and the legacy lowercase ones; anything non-terminal returns null
     * so the caller ignores it (no state change).
     */
    private function normalizeStatus(?string $status): ?string
    {
        return match ($status) {
            'Approved', 'approved' => 'approved',
            'Declined', 'rejected' => 'rejected',
            default => null,
        };
    }
}
