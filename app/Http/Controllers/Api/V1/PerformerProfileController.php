<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ImageProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\PerformerPrivateResource;
use App\Services\PerformerProfileService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PerformerProfileController extends Controller
{
    public function __construct(private PerformerProfileService $profileService) {}

    public function show(Request $request): PerformerPrivateResource
    {
        $profile = $request->user()->performerProfile;

        abort_if(! $profile, 404);

        return new PerformerPrivateResource($profile);
    }

    public function update(UpdatePerformerProfileRequest $request): PerformerPrivateResource
    {
        $profile = $request->user()->performerProfile;

        abort_if(! $profile, 404);

        $this->authorize('update', $profile);

        $data = $request->validated();

        // Mesmo serviço do onboarding e da edição web: o slug regenera no rename
        // em TODAS as superfícies. Duplicar a regra aqui era o que deixava esta
        // rota renomear sem trocar a URL, preservando o nome antigo em público.
        $this->profileService->update($profile, $data);

        Audit::log('performer_profile_updated', $profile, ['fields' => array_keys($data)], $request);

        return new PerformerPrivateResource($profile->fresh());
    }

    public function avatar(UploadMediaRequest $request): JsonResponse
    {
        $profile = $request->user()->performerProfile;

        abort_if(! $profile, 404);

        $this->authorize('update', $profile);

        // Mesmo serviço da edição web/onboarding: higieniza (strip EXIF/GPS +
        // re-encode) e descarta o arquivo anterior. Duplicar o storeAs aqui era o
        // que deixava a foto de celular subir com coordenadas GPS pela API — o
        // avatar é público (leak de localização da performer, UAT R3).
        try {
            $this->profileService->replaceAvatar($profile, $request->file('file'));
        } catch (ImageProcessingException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        Audit::log('performer_avatar_updated', $profile, null, $request);

        return response()->json([
            'avatar_url' => URL::temporarySignedRoute(
                'performer.media',
                now()->addMinutes(60),
                ['profile_id' => $profile->id, 'type' => 'avatar']
            ),
        ]);
    }

    public function cover(UploadMediaRequest $request): JsonResponse
    {
        $profile = $request->user()->performerProfile;

        abort_if(! $profile, 404);

        $this->authorize('update', $profile);

        // Mesmo serviço da edição web: redimensiona 1200x400 (3:1) + higieniza.
        // Sem isso, a API era a segunda porta que subia a capa em tamanho
        // original (UAT R3) — a regra vive num lugar só (PerformerProfileService).
        try {
            $this->profileService->replaceCover($profile, $request->file('file'));
        } catch (ImageProcessingException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        Audit::log('performer_cover_updated', $profile, null, $request);

        return response()->json([
            'cover_url' => URL::temporarySignedRoute(
                'performer.media',
                now()->addMinutes(60),
                ['profile_id' => $profile->id, 'type' => 'cover']
            ),
        ]);
    }
}
