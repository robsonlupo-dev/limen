<?php

namespace App\Services\Kyc;

interface KycClientInterface
{
    public function submitVerification(array $data): array;

    public function getVerification(string $ref): array;
}
