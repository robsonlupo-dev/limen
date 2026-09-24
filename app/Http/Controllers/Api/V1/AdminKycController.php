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
use Illuminate\Support\Facades\DB;

class AdminKycController extends Controller
{
    /** Estados que ainda podem transicionar (mesma lista do painel web e do webhook). */
    private const ACTIONABLE = ['pending', 'review'];

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

    /**
     * lockForUpdate + re-check do status, igual ao painel web e ao webhook Didit:
     * dois admins (ou um admin correndo contra o webhook) não aprovam duas vezes
     * nem disparam e-mail/carta em dobro. Já terminal → no-op idempotente (200).
     */
    public function approve(Request $request, IdentityVerification $verification): JsonResponse
    {
        return DB::transaction(function () use ($request, $verification) {
            $verification = IdentityVerification::whereKey($verification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($verification->status, self::ACTIONABLE, true)) {
                return response()->json(['message' => "Verificação já está \"{$verification->status}\" — nada foi regravado."]);
            }

            $this->kycService->approve($verification, $request->user()->id);

            return response()->json(['message' => 'Verificação aprovada.']);
        });
    }

    public function reject(AdminKycRejectRequest $request, IdentityVerification $verification): JsonResponse
    {
        return DB::transaction(function () use ($request, $verification) {
            $verification = IdentityVerification::whereKey($verification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($verification->status, self::ACTIONABLE, true)) {
                return response()->json(['message' => "Verificação já está \"{$verification->status}\" — nada foi regravado."]);
            }

            $this->kycService->reject($verification, $request->input('reason'), $request->user()->id);

            return response()->json(['message' => 'Verificação rejeitada.']);
        });
    }
}
