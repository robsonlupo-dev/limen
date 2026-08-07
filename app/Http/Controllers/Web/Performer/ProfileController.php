<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerProfileRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Models\PerformerProfile;
use App\Services\PerformerProfileService;
use App\Exceptions\ImageProcessingException;
use App\Support\Audit;
use App\Support\PhotoGalleryPresenter;
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
                // Localização (Sprint 13). `city` aparece AQUI e em nenhum outro
                // lugar: é a tela em que a própria performer edita o que ela
                // mesma digitou, não uma superfície de exibição. `state`/`city`
                // seguem como o CACHE da primária (compat com o Sprint 9A); a
                // lista completa vai em `locations`, que a nova seção edita e
                // salva pela porta dedicada. `city` de cada linha só existe nesta
                // tela — ver UpdatePerformerLocationsRequest e o resource público.
                'state' => $profile->state,
                'city' => $profile->city,
                'locations' => $profile->locations->map(fn ($l) => [
                    'state' => $l->state,
                    'city' => $l->city,
                    'is_primary' => $l->is_primary,
                ])->all(),
                'avatar_url' => $profile->avatar_path
                    ? URL::temporarySignedRoute('performer.media', now()->addMinutes(60), [
                        'profile_id' => $profile->id,
                        'type' => 'avatar',
                    ])
                    : null,
                'cover_url' => $profile->cover_path
                    ? URL::temporarySignedRoute('performer.media', now()->addMinutes(60), [
                        'profile_id' => $profile->id,
                        'type' => 'cover',
                    ])
                    : null,
            ],
            // Galeria de fotos (Sprint 10): a lista atual, na ordem do carrossel,
            // e o teto para a tela desenhar "N/6". As mutações (upload, delete,
            // reordenar) vão por endpoints JSON próprios que devolvem a lista
            // atualizada — esta prop é só o estado inicial.
            // Viewer é a própria dona: vê todas as fotos com o estado do cadeado
            // (`is_private`) por foto, para o toggle do grid (Sprint 13).
            'photos' => PhotoGalleryPresenter::forProfile($profile, $request->user()),
            'maxPhotos' => PerformerProfile::MAX_PHOTOS,
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
            // Localização saiu daqui (Sprint 13): lista própria em
            // performer.locations.update. Este save não toca mais nas colunas
            // `state`/`city` (que agora são cache da primária, escrito só pelo
            // PerformerLocationService).
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
                ? 'Perfil atualizado. O endereço do seu perfil mudou; links antigos passam a redirecionar para o novo.'
                : 'Perfil atualizado.',
        );
    }

    public function avatar(UploadMediaRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        // Imagem-bomba / corrompida / formato falha DENTRO do pipeline (as guardas
        // do ImageProcessingService), depois do UploadMediaRequest, que só vê
        // mime+tamanho e não dimensões. Traduz para erro de sessão (o form Inertia
        // lê errors.file) em vez de deixar a DomainException virar 500.
        try {
            $this->profileService->replaceAvatar($profile, $request->file('file'));
        } catch (ImageProcessingException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        Audit::log('performer_avatar_updated', $profile, null, $request);

        return back()->with('success', 'Foto de perfil atualizada.');
    }

    public function cover(UploadMediaRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        try {
            $this->profileService->replaceCover($profile, $request->file('file'));
        } catch (ImageProcessingException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        Audit::log('performer_cover_updated', $profile, null, $request);

        return back()->with('success', 'Foto de capa atualizada.');
    }
}
