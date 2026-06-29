<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycRequest;
use App\Services\Kyc\KycClientInterface;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    public function __construct(private KycClientInterface $kycClient) {}

    public function submit(SubmitKycRequest $request): JsonResponse
    {
        $user = $request->user();

        $verification = $user->identityVerifications()
            ->whereNotIn('status', ['rejected'])
            ->latest()
            ->first();

        if ($verification && $verification->status === 'pending') {
            return response()->json([
                'message' => 'Você já possui uma verificação pendente.',
            ], 422);
        }

        $userId = $user->id;
        $frontPath = Storage::disk('local')->putFileAs(
            "kyc/{$userId}",
            $request->file('document_front'),
            'document_front.' . $request->file('document_front')->extension()
        );

        $backPath = null;
        if ($request->hasFile('document_back')) {
            $backPath = Storage::disk('local')->putFileAs(
                "kyc/{$userId}",
                $request->file('document_back'),
                'document_back.' . $request->file('document_back')->extension()
            );
        }

        $selfiePath = Storage::disk('local')->putFileAs(
            "kyc/{$userId}",
            $request->file('selfie'),
            'selfie.' . $request->file('selfie')->extension()
        );

        $providerResponse = $this->kycClient->submitVerification([
            'document_type' => $request->input('document_type'),
            'cpf' => preg_replace('/\D/', '', $request->input('cpf')),
            'full_legal_name' => $request->input('full_legal_name'),
            'date_of_birth' => $request->input('date_of_birth'),
        ]);

        $newVerification = $user->identityVerifications()->create([
            'document_type' => $request->input('document_type'),
            'document_number' => preg_replace('/\D/', '', $request->input('cpf')),
            'full_legal_name' => $request->input('full_legal_name'),
            'date_of_birth' => $request->input('date_of_birth'),
            'document_front_path' => $frontPath,
            'document_back_path' => $backPath,
            'selfie_path' => $selfiePath,
            'provider' => config('kyc.provider'),
            'provider_reference' => $providerResponse['reference'] ?? null,
            'provider_status' => $providerResponse['status'] ?? 'pending',
            'status' => 'pending',
        ]);

        Audit::log('kyc.submitted', $newVerification);

        return response()->json([
            'status' => 'pending',
            'message' => 'Verificação enviada com sucesso.',
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $verification = $request->user()
            ->identityVerifications()
            ->latest()
            ->first();

        if (! $verification) {
            return response()->json(['status' => 'not_submitted']);
        }

        return response()->json([
            'status' => $verification->status,
            'age_confirmed' => $verification->age_confirmed,
            'reviewed_at' => $verification->reviewed_at?->toISOString(),
            'submitted_at' => $verification->created_at->toISOString(),
        ]);
    }
}
