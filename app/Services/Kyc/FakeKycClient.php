<?php

namespace App\Services\Kyc;

class FakeKycClient implements KycClientInterface
{
    private array $verifications = [];

    public function submitVerification(array $data): array
    {
        $ref = 'kyc_fake_' . uniqid();

        $this->verifications[$ref] = [
            'reference' => $ref,
            'status' => 'pending',
            'provider' => 'fake',
        ];

        return $this->verifications[$ref];
    }

    public function getVerification(string $ref): array
    {
        return $this->verifications[$ref] ?? [
            'reference' => $ref,
            'status' => 'pending',
            'provider' => 'fake',
        ];
    }

    public function simulateApproved(string $ref): void
    {
        if (isset($this->verifications[$ref])) {
            $this->verifications[$ref]['status'] = 'approved';
        }
    }

    public function simulateRejected(string $ref, string $reason = 'Document unclear'): void
    {
        if (isset($this->verifications[$ref])) {
            $this->verifications[$ref]['status'] = 'rejected';
            $this->verifications[$ref]['reason'] = $reason;
        }
    }
}
