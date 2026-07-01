<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasTransferWebhookController extends Controller
{
    public function __construct(private PayoutService $payoutService) {}

    public function handle(Request $request): JsonResponse
    {
        $expectedToken = config('asaas.webhook_token');

        if (! $expectedToken || ! hash_equals($expectedToken, (string) $request->header('asaas-access-token'))) {
            Log::warning('Asaas transfer webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('webhook.transfer_auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $this->payoutService->handleWebhook($request->all());

        return response()->json(['message' => 'OK.']);
    }
}
