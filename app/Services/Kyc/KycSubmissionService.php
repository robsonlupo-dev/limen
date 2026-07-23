<?php

namespace App\Services\Kyc;

use App\Models\IdentityVerification;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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

    /**
     * Persiste documentos (criptografados, disco `kyc`), envia ao provider e
     * cria a verificação `pending`. A checagem de duplicata vive AQUI, dentro
     * da transação — não no chamador: check-then-act no controller reabria a
     * corrida que esta transação fecha.
     *
     * @throws DuplicateKycSubmissionException já há verificação ativa
     */
    public function submit(
        User $user,
        array $data,
        UploadedFile $documentFront,
        ?UploadedFile $documentBack,
        UploadedFile $selfie,
    ): IdentityVerification {
        return DB::transaction(function () use ($user, $data, $documentFront, $documentBack, $selfie) {
            // Serializa envios DESTE usuário travando a linha dele como
            // primeira instrução da transação (idioma do InterestService::send).
            // O lockForUpdate do exists() abaixo NÃO basta sozinho: no primeiro
            // envio não há linha de verificação para travar — dois gap locks
            // convivem e o par de POSTs vira deadlock, não fila.
            User::whereKey($user->id)->lockForUpdate()->first();

            $active = $user->identityVerifications()
                ->whereNotIn('status', ['rejected'])
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw DuplicateKycSubmissionException::make();
            }

            $frontPath = $this->documents->store($user->id, $documentFront, 'document_front');
            $backPath = $documentBack
                ? $this->documents->store($user->id, $documentBack, 'document_back')
                : null;
            $selfiePath = $this->documents->store($user->id, $selfie, 'selfie');

            $cpf = preg_replace('/\D/', '', $data['cpf']);

            // Chamada externa segurando o lock: aceitável aqui — o envio de KYC
            // acontece ~1x por performer, e soltar o lock antes do INSERT
            // reabriria exatamente a janela que a transação fecha.
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
        });
    }
}
