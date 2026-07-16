<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AsaasWebhookController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = config('asaas.webhook_token');

        if (! $expectedToken || ! hash_equals($expectedToken, (string) $request->header('asaas-access-token'))) {
            Log::warning('Asaas webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('webhook.auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = $request->all();

        // Asaas posts every event to the same URL. Subscription lifecycle events
        // and any payment tied to a subscription go to the subscription handler;
        // one-off token-purchase charges stay with the payment handler.
        $event = (string) ($payload['event'] ?? '');
        $isSubscription = Str::startsWith($event, 'SUBSCRIPTION')
            || ! empty($payload['payment']['subscription']);

        if ($isSubscription) {
            $this->subscriptionService->handleWebhook($payload);
        } else {
            $this->paymentService->handleWebhook($payload);
        }

        return response()->json(['message' => 'OK.']);
    }
}
