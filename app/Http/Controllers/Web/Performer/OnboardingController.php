<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Models\PerformerProfile;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
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
        ]);
    }

    public function updateProfile(UpdatePerformerProfileRequest $request): RedirectResponse
    {
        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        $data = $request->validated();

        if (! $profile->slug) {
            $data['slug'] = PerformerProfile::generateSlug($data['stage_name'] ?? $profile->stage_name);
        }

        $profile->update($data);

        Audit::log('performer_profile_updated', $profile, ['fields' => array_keys($data)], $request);

        return back()->with('success', 'Perfil atualizado.');
    }

    public function avatar(UploadMediaRequest $request): RedirectResponse
    {
        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        if ($profile->avatar_path) {
            Storage::disk('local')->delete($profile->avatar_path);
        }

        $ext = $request->file('file')->extension();
        $path = $request->file('file')->storeAs(
            "performer-media/{$request->user()->id}",
            "avatar.{$ext}",
            'local'
        );

        $profile->update(['avatar_path' => $path]);

        Audit::log('performer_avatar_updated', $profile, null, $request);

        return back()->with('success', 'Foto de perfil atualizada.');
    }
}
