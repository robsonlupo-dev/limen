<?php

namespace App\Services\Kyc;

use App\Models\IdentityVerification;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/**
 * Fonte única do envio de KYC. As DUAS portas (API Sanctum e onboarding web)
 * delegam aqui — mesma razão do FollowerVisibilityService: se divergissem, o
 * que uma porta aceita e a outra recusa viraria comportamento distinto para a
 * mesma performer.
 */
class KycSubmissionService
{
    public function __construct(
        private KycClientInterface $kycClient,
        private KycDocumentStore $documents,
    ) {}

    /** Já existe verificação que não foi rejeitada (pending/review/approved)? */
    public function hasActiveVerification(User $user): bool
    {
        return $user->identityVerifications()
            ->whereNotIn('status', ['rejected'])
            ->exists();
    }

    /**
     * Persiste documentos (criptografados, disco `kyc`), envia ao provider e
     * cria a verificação `pending`. O chamador é responsável por checar
     * hasActiveVerification() antes — cada porta responde a duplicata no seu
     * formato (422 JSON vs redirect com erro).
     */
    public function submit(
        User $user,
        array $data,
        UploadedFile $documentFront,
        ?UploadedFile $documentBack,
        UploadedFile $selfie,
    ): IdentityVerification {
        $frontPath = $this->documents->store($user->id, $documentFront, 'document_front');
        $backPath = $documentBack
            ? $this->documents->store($user->id, $documentBack, 'document_back')
            : null;
        $selfiePath = $this->documents->store($user->id, $selfie, 'selfie');

        $cpf = preg_replace('/\D/', '', $data['cpf']);

        $providerResponse = $this->kycClient->submitVerification([
            'document_type' => $data['document_type'],
            'cpf' => $cpf,
            'full_legal_name' => $data['full_legal_name'],
            'date_of_birth' => $data['date_of_birth'],
        ]);

        $verification = $user->identityVerifications()->create([
            'document_type' => $data['document_type'],
            'document_number' => $cpf,
            'full_legal_name' => $data['full_legal_name'],
            'date_of_birth' => $data['date_of_birth'],
            'document_front_path' => $frontPath,
            'document_back_path' => $backPath,
            'selfie_path' => $selfiePath,
            'provider' => config('kyc.provider'),
            'provider_reference' => $providerResponse['reference'] ?? null,
            'provider_status' => $providerResponse['status'] ?? 'pending',
            'status' => 'pending',
        ]);

        Audit::log('kyc.submitted', $verification);

        return $verification;
    }
}
