<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminKycRejectRequest;
use App\Http\Resources\IdentityVerificationResource;
use App\Models\IdentityVerification;
use App\Services\KycService;
use App\Services\SharedRegistrationIpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminKycController extends Controller
{
    public function __construct(
        private KycService $kycService,
        private SharedRegistrationIpService $sharedIps,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $verifications = IdentityVerification::with(['user.performerProfile'])
            ->whereIn('status', ['pending', 'review'])
            ->latest()
            ->paginate(20);

        // Sinal de possível rede de exploração, resolvido em UMA query para a
        // página toda (não uma por linha) e anexado ao model — o resource não
        // consulta banco. Fica aqui, na fila de KYC, porque é o momento em que
        // o admin decide aprovar a performer: sinal que chega depois da decisão
        // não muda decisão nenhuma.
        $counts = $this->sharedIps->othersCountFor(
            collect($verifications->items())->pluck('user')->filter()
        );

        foreach ($verifications as $verification) {
            $verification->user?->setAttribute(
                'shared_registration_ip_others',
                $counts[$verification->user->id] ?? 0
            );
        }

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
