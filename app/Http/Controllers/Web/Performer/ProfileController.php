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
                // `worlds` pode ser null (linha anterior ao multi-worlds); a
                // tela usa `worlds ?? [category]` para pré-selecionar. Mando os
                // dois para o legado cair no fallback sem uma query extra.
                'worlds' => $profile->worlds,
                'category' => $profile->category,
                // "Sobre mim". `tags` sai da junção como lista de slugs — a tela
                // marca os chips por slug, e o rótulo é dela.
                'tags' => $profile->tagSlugs(),
                'languages' => $profile->languages ?? [],
                'drinks' => $profile->drinks,
                'smokes' => $profile->smokes,
                'height_cm' => $profile->height_cm,
                'looking_for' => $profile->looking_for,
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

        $validated = $request->validated();

        // Só os campos desta tela. O request valida o perfil inteiro (é o mesmo
        // do onboarding), então filtrar aqui impede que tarifas — e a `category`
        // crua — sejam alteradas por um POST forjado a partir de uma tela que
        // não as oferece.
        $data = array_intersect_key($validated, array_flip([
            'stage_name', 'bio',
            // "Sobre mim" (Sprint 9). `tags` NÃO entra aqui: não é coluna, vai
            // pela junção logo abaixo.
            'languages', 'drinks', 'smokes', 'height_cm', 'looking_for',
        ]));

        // Multi-worlds: se a tela mandou a seleção de mundos, ela é a fonte da
        // verdade. A `category` (compat legado) é DERIVADA de worlds[0] aqui no
        // servidor — nunca vinda do request — para os dois nunca discordarem.
        // Mesma regra do cadastro (RegisterWebRequest::prepareForValidation).
        // Sem `worlds` no request, `category`/`worlds` ficam intocados: é o
        // caminho legado, que continua igual (POST só de category é ignorado).
        if (! empty($validated['worlds'])) {
            $worlds = array_values(array_unique($validated['worlds']));
            $data['worlds'] = $worlds;
            $data['category'] = $worlds[0];
        }

        $renamed = isset($data['stage_name']) && $data['stage_name'] !== $profile->stage_name;

        $this->profileService->update($profile, $data);

        // Tags vão pela junção, não pelo update de colunas. `array_key_exists` e
        // não `! empty`: a tela manda `tags: []` quando a performer desmarca
        // todas, e isso precisa apagar o conjunto. Com `! empty` o array vazio
        // seria lido como "campo ausente" e as tags antigas sobreviveriam a uma
        // limpeza deliberada.
        $syncedTags = array_key_exists('tags', $validated);

        if ($syncedTags) {
            $profile->syncTags($validated['tags'] ?? []);
        }

        Audit::log('performer_profile_updated', $profile, [
            'fields' => $syncedTags ? [...array_keys($data), 'tags'] : array_keys($data),
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
