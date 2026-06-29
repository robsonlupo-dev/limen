<?php

namespace App\Services\Kyc;

use Illuminate\Support\Facades\Http;

class KycHttpClient implements KycClientInterface
{
    public function submitVerification(array $data): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('kyc.api_key'),
        ])->post(config('kyc.base_url') . '/verifications', $data);

        $response->throw();

        return $response->json();
    }

    public function getVerification(string $ref): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('kyc.api_key'),
        ])->get(config('kyc.base_url') . '/verifications/' . $ref);

        $response->throw();

        return $response->json();
    }
}
