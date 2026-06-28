<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\PerformerPrivateResource;
use App\Models\PerformerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PerformerProfileController extends Controller
{
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

        if (! $profile->slug) {
            $stageName = $data['stage_name'] ?? $profile->stage_name;
            $data['slug'] = PerformerProfile::generateSlug($stageName);
        }

        $profile->update($data);

        return new PerformerPrivateResource($profile->fresh());
    }

    public function avatar(UploadMediaRequest $request): JsonResponse
    {
        $profile = $request->user()->performerProfile;

        abort_if(! $profile, 404);

        $ext  = $request->file('file')->extension();
        $path = $request->file('file')->storeAs(
            "performer-media/{$request->user()->id}",
            "avatar.{$ext}",
            'local'
        );

        $profile->update(['avatar_path' => $path]);

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

        $ext  = $request->file('file')->extension();
        $path = $request->file('file')->storeAs(
            "performer-media/{$request->user()->id}",
            "cover.{$ext}",
            'local'
        );

        $profile->update(['cover_path' => $path]);

        return response()->json([
            'cover_url' => URL::temporarySignedRoute(
                'performer.media',
                now()->addMinutes(60),
                ['profile_id' => $profile->id, 'type' => 'cover']
            ),
        ]);
    }
}
