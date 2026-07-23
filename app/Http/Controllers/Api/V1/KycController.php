<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycRequest;
use App\Services\Kyc\KycSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(private KycSubmissionService $submission) {}

    public function submit(SubmitKycRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->submission->hasActiveVerification($user)) {
            return response()->json([
                'message' => 'Você já possui uma verificação ativa ou pendente.',
            ], 422);
        }

        $this->submission->submit(
            $user,
            $request->only(['document_type', 'cpf', 'full_legal_name', 'date_of_birth']),
            $request->file('document_front'),
            $request->file('document_back'),
            $request->file('selfie'),
        );

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
