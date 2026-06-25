<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasWebhookController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = config('asaas.webhook_token');

        if (! $expectedToken || ! hash_equals($expectedToken, (string) $request->header('asaas-access-token'))) {
            Log::warning('Asaas webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('webhook.auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $this->paymentService->handleWebhook($request->all());

        return response()->json(['message' => 'OK.']);
    }
}
