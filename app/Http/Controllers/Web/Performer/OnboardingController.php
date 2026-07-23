<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycRequest;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Services\Kyc\DuplicateKycSubmissionException;
use App\Services\Kyc\KycSubmissionService;
use App\Services\PerformerProfileService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(
        private PerformerProfileService $profileService,
        private KycSubmissionService $kycSubmission,
    ) {}

    public function index(Request $request): Response
    {
        $profile = $request->user()->performerProfile;
        $verification = $request->user()->identityVerifications()->latest()->first();

        return Inertia::render('Performer/Onboarding', [
            'profile' => $profile ? [
                'stage_name' => $profile->stage_name,
                'bio' => $profile->bio,
                'category' => $profile->category,
                'rate_public' => $profile->rate_public,
                'avatar_url' => $profile->avatar_path
                    ? URL::temporarySignedRoute('performer.media', now()->addMinutes(60), [
                        'profile_id' => $profile->id,
                        'type' => 'avatar',
                    ])
                    : null,
            ] : null,
            'kycStatus' => $verification?->status ?? 'not_submitted',
            'kycRejectionReason' => $this->rejectionReasonFor($verification),
        ]);
    }

    /**
     * A razão da rejeição não é coluna de identity_verifications: vive no
     * metadata do audit `kyc.rejected` (KycService::reject). Lê de lá — criar
     * uma segunda cópia da razão só para a tela dessincronizaria as duas.
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

    /**
     * Porta WEB do envio de KYC (o front Inertia fala com sessão + CSRF; a rota
     * API `performer.kyc.submit` é Sanctum e não enxerga a sessão). As duas
     * portas delegam ao mesmo KycSubmissionService.
     */
    public function submitKyc(SubmitKycRequest $request): RedirectResponse
    {
        try {
            $this->kycSubmission->submit(
                $request->user(),
                $request->only(['document_type', 'cpf', 'full_legal_name', 'date_of_birth']),
                $request->file('document_front'),
                $request->file('document_back'),
                $request->file('selfie'),
            );
        } catch (DuplicateKycSubmissionException $e) {
            return back()->withErrors(['kyc' => $e->getMessage()]);
        }

        return back()->with('success', 'Verificação enviada com sucesso.');
    }

    public function updateProfile(UpdatePerformerProfileRequest $request): RedirectResponse
    {
        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        $data = $request->validated();
        $this->profileService->update($profile, $data);

        Audit::log('performer_profile_updated', $profile, ['fields' => array_keys($data)], $request);

        return back()->with('success', 'Perfil atualizado.');
    }

    public function avatar(UploadMediaRequest $request): RedirectResponse
    {
        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        $this->profileService->replaceAvatar($profile, $request->file('file'));

        Audit::log('performer_avatar_updated', $profile, null, $request);

        return back()->with('success', 'Foto de perfil atualizada.');
    }
}
