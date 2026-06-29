<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminKycRejectRequest;
use App\Http\Resources\IdentityVerificationResource;
use App\Models\IdentityVerification;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminKycController extends Controller
{
    public function __construct(private KycService $kycService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $verifications = IdentityVerification::with(['user.performerProfile'])
            ->whereIn('status', ['pending', 'review'])
            ->latest()
            ->paginate(20);

        return IdentityVerificationResource::collection($verifications);
    }

    public function approve(Request $request, IdentityVerification $verification): JsonResponse
    {
        $this->kycService->approve($verification, $request->user()->id);

        return response()->json(['message' => 'Verificação aprovada.']);
    }

    public function reject(AdminKycRejectRequest $request, IdentityVerification $verification): JsonResponse
    {
        $this->kycService->reject($verification, $request->input('reason'), $request->user()->id);

        return response()->json(['message' => 'Verificação rejeitada.']);
    }
}
