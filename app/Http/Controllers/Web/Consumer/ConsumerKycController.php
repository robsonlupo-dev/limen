<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Services\Kyc\DuplicateKycSubmissionException;
use App\Services\Kyc\KycSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KYC Nível 2 do MEMBRO — envio da selfie de verificação (selfie-only; o
 * documento fica para o Sprint 9). Fora do grupo role:consumer/member.verified
 * de propósito: é o destino do redirect do EnsureMemberVerified, e gatear a
 * própria tela de saída daria loop. O corte por papel vive aqui — estas rotas
 * são só do membro.
 */
class ConsumerKycController extends Controller
{
    /** Estados de verificação que ainda seguram o membro na sala de espera. */
    private const ACTIVE = ['pending', 'review'];

    public function __construct(private KycSubmissionService $kycSubmission) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'consumer', 403);

        // Já verificado: não há o que enviar. Volta para o painel.
        if ($user->status === 'active') {
            return redirect()->route('consumer.dashboard');
        }

        $verification = $user->identityVerifications()->latest()->first();

        // Verificação em andamento (pending/review): a sala de espera é o lugar.
        // Rejeitada cai fora deste if e renderiza o formulário de reenvio.
        if ($verification && in_array($verification->status, self::ACTIVE, true)) {
            return redirect()->route('consumer.kyc.waiting');
        }

        return Inertia::render('Consumer/Kyc/Index', [
            'kycStatus' => $verification?->status ?? 'not_submitted',
            'kycRejectionReason' => $this->rejectionReasonFor($verification),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'consumer', 403);

        $request->validate(
            ['selfie' => ['required', 'file', 'mimes:jpeg,png', 'max:10240']],
            [
                'selfie.required' => 'Envie uma selfie para continuar.',
                'selfie.mimes' => 'A selfie deve ser jpeg ou png.',
                'selfie.max' => 'O arquivo não pode exceder 10MB.',
            ],
        );

        try {
            $this->kycSubmission->submitMemberSelfie($request->user(), $request->file('selfie'));
        } catch (DuplicateKycSubmissionException $e) {
            return back()->withErrors(['selfie' => $e->getMessage()]);
        }

        return redirect()->route('consumer.kyc.waiting');
    }

    public function waiting(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->role === 'consumer', 403);

        $verification = $user->identityVerifications()->latest()->first();

        return Inertia::render('Consumer/Kyc/Waiting', [
            'kycStatus' => $verification?->status ?? 'not_submitted',
            'kycRejectionReason' => $this->rejectionReasonFor($verification),
        ]);
    }

    /**
     * A razão da rejeição não é coluna de identity_verifications: vive no
     * metadata do audit `kyc.rejected` (KycService::reject) — mesma fonte que a
     * OnboardingController da performer lê. Uma segunda cópia dessincronizaria.
     */
    private function rejectionReasonFor(?IdentityVerification $verification): ?string
    {
        if ($verification?->status !== 'rejected') {
            return null;
        }

        $metadata = AuditLog::where('action', 'kyc.rejected')
            ->where('subject_type', $verification->getMorphClass())
            ->where('subject_id', $verification->getKey())
            ->latest('id')
            ->value('metadata');

        return $metadata['reason'] ?? null;
    }
}
