<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\TokenPackage;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

    public function store(CreatePaymentRequest $request): JsonResponse
    {
        $package = TokenPackage::where('id', $request->validated('token_package_id'))
            ->where('active', true)
            ->first();

        if (! $package) {
            return response()->json([
                'message' => 'Token package not found or inactive.',
                'errors' => ['token_package_id' => ['The selected token package is not available.']],
            ], 422);
        }

        $payment = $this->paymentService->createPayment($request->user(), $package, $request->validated('cpf'));

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return PaymentResource::collection($payments);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payment);
    }
}
