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
        $expectedSecret = config('kyc.webhook_secret');

        if (! $expectedSecret || ! hash_equals($expectedSecret, (string) $request->header('X-Kyc-Secret'))) {
            Log::warning('KYC webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('kyc.webhook_auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $providerReference = $request->input('reference');
        $status = $request->input('status');
        $reason = $request->input('reason');

        $verification = IdentityVerification::where('provider_reference', $providerReference)->first();

        if (! $verification) {
            return response()->json(['message' => 'OK.']);
        }

        // Idempotency: skip if already in the requested status
        if ($verification->status === $status) {
            return response()->json(['message' => 'OK.']);
        }

        if ($status === 'approved') {
            $this->kycService->approve($verification);
        } elseif ($status === 'rejected') {
            $this->kycService->reject($verification, $reason);
        }

        return response()->json(['message' => 'OK.']);
    }
}
