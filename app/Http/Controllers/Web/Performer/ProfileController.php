<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Services\PerformerProfileService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Edição de perfil da performer ativa. O onboarding é o wizard de quem ainda
 * não entrou; esta é a tela de quem já está no ar. As mutações são as mesmas
 * (PerformerProfileService) — muda só a superfície.
 */
class ProfileController extends Controller
{
    public function __construct(private PerformerProfileService $profileService) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;

        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        return Inertia::render('Performer/Profile/Edit', [
            'profile' => [
                'stage_name' => $profile->stage_name,
                'bio' => $profile->bio,
                'slug' => $profile->slug,
                'avatar_url' => $profile->avatar_path
                    ? URL::temporarySignedRoute('performer.media', now()->addMinutes(60), [
                        'profile_id' => $profile->id,
                        'type' => 'avatar',
                    ])
                    : null,
            ],
        ]);
    }

    public function update(UpdatePerformerProfileRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        // Só os campos desta tela. O request valida o perfil inteiro (é o mesmo
        // do onboarding), então filtrar aqui impede que categoria/tarifas sejam
        // alteradas por um POST forjado a partir de uma tela que não as oferece.
        $data = array_intersect_key($request->validated(), array_flip(['stage_name', 'bio']));

        $renamed = isset($data['stage_name']) && $data['stage_name'] !== $profile->stage_name;

        $this->profileService->update($profile, $data);

        Audit::log('performer_profile_updated', $profile, [
            'fields' => array_keys($data),
            'renamed' => $renamed,
        ], $request);

        return back()->with(
            'success',
            $renamed
                ? 'Perfil atualizado. O endereço do seu perfil mudou — links antigos não funcionam mais.'
                : 'Perfil atualizado.',
        );
    }

    public function avatar(UploadMediaRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        $this->profileService->replaceAvatar($profile, $request->file('file'));

        Audit::log('performer_avatar_updated', $profile, null, $request);

        return back()->with('success', 'Foto de perfil atualizada.');
    }
}
