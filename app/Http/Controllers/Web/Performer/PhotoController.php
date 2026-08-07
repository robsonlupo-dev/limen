<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\PhotoGalleryException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ReorderPerformerPhotosRequest;
use App\Http\Requests\Web\StorePerformerPhotoRequest;
use App\Http\Requests\Web\TogglePhotoVisibilityRequest;
use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PerformerPhotoService;
use App\Services\PerformerPhotoStore;
use App\Services\PhotoAccessService;
use App\Support\Audit;
use App\Support\PhotoGalleryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Galeria de fotos do perfil da performer (Sprint 10; visibilidade no Sprint 13).
 *
 * O controller só delega — cap de 6, propriedade e ordem vivem no
 * `PerformerPhotoService`; visibilidade e grants, no `PhotoAccessService`. A
 * tradução para HTTP é responsabilidade daqui, e não é automática: o front fala
 * com rotas WEB, onde uma exceção não vira JSON sozinha (só em `api/*`). Sem o
 * `response()->json()` explícito o Vue receberia HTML.
 *
 * `image()` é a única ação PÚBLICA de leitura, mas desde o Sprint 13 aplica o
 * paywall por foto: foto privada sem grant (nem a dona) é 404. As de gestão
 * ficam atrás de auth + role:performer + 2fa + documents.accepted +
 * performer-active, como toda rota nova da performer (CLAUDE.md).
 */
class PhotoController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private PerformerPhotoService $photos,
        private PerformerPhotoStore $store,
        private PhotoAccessService $access,
    ) {}

    /**
     * Adiciona uma foto ao fim da galeria.
     *
     * `ImageProcessingException` em 422 (imagem-bomba, formato, corrompida) e
     * `PhotoGalleryException` de cap também em 422 — os dois são erros que a tela
     * sabe explicar. Devolve a lista atualizada para o front repintar o grid.
     */
    public function store(StorePerformerPhotoRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $photo = $this->photos->add($profile, $request->file('foto'));
        } catch (ImageProcessingException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        } catch (PhotoGalleryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        } catch (CsamDetectedException $e) {
            return response()->json(['reason' => 'rejected', 'message' => $e->getMessage()], 422);
        }

        Audit::log('performer_photo_added', $profile, ['photo_id' => $photo->id], $request);

        return response()->json([
            'photos' => $this->payload($profile, $request->user()),
        ], 201);
    }

    /**
     * Apaga uma foto. Só a dona — foto de outra performer devolve 403 uniforme.
     *
     * Entra pelo Service (que checa a propriedade), nunca apagando o model
     * bindado direto: com route-model binding e um controller que só delega, isso
     * deixaria qualquer performer autenticada apagar a foto de outra.
     */
    public function destroy(Request $request, PerformerPhoto $photo): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->photos->delete($profile, $photo);
        } catch (PhotoGalleryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 403);
        }

        Audit::log('performer_photo_deleted', $profile, ['photo_id' => $photo->id], $request);

        return response()->json([
            'photos' => $this->payload($profile, $request->user()),
        ]);
    }

    /**
     * Reordena a galeria. A lista de ids tem de bater com a galeria da performer;
     * qualquer divergência é 422 (ver PerformerPhotoService::reorder).
     */
    public function reorder(ReorderPerformerPhotosRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->photos->reorder($profile, $request->validated()['ids']);
        } catch (PhotoGalleryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        Audit::log('performer_photos_reordered', $profile, null, $request);

        return response()->json([
            'photos' => $this->payload($profile, $request->user()),
        ]);
    }

    /**
     * Alterna público/privado de uma foto (Sprint 13). Só a dona — foto de outra
     * performer é 403 uniforme, como o delete. Devolve a galeria repintada.
     */
    public function visibility(TogglePhotoVisibilityRequest $request, PerformerPhoto $photo): JsonResponse
    {
        $profile = $this->profile($request);

        try {
            $this->access->setVisibility($profile, $photo, $request->boolean('is_private'));
        } catch (PhotoGalleryException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 403);
        }

        Audit::log('performer_photo_visibility_changed', $profile, [
            'photo_id' => $photo->id,
            'is_private' => $request->boolean('is_private'),
        ], $request);

        return response()->json([
            'photos' => $this->payload($profile, $request->user()),
        ]);
    }

    /**
     * A fila de pedidos de acesso pendentes, pseudonimizada (FanAlias). Usada
     * para recarregar a seção do painel depois de aprovar/revogar.
     */
    public function requests(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'requests' => $this->access->pendingRequestsFor($profile),
        ]);
    }

    /**
     * Aprova o pedido de um membro (pelo handle do FanAlias, nunca pelo id).
     * Handle que não casa com um pendente desta foto → 404 (no service). Devolve
     * a fila atualizada.
     */
    public function grant(Request $request, PerformerPhoto $photo, string $member_handle): JsonResponse
    {
        $profile = $this->profile($request);

        $this->access->grant($profile, $photo, $member_handle);

        Audit::log('performer_photo_access_granted', $profile, ['photo_id' => $photo->id], $request);

        return response()->json([
            'requests' => $this->access->pendingRequestsFor($profile),
        ]);
    }

    /**
     * Revoga um acesso OU recusa um pedido (mesmo verbo). Handle pelo FanAlias.
     * Devolve a fila atualizada.
     */
    public function revoke(Request $request, PerformerPhoto $photo, string $member_handle): JsonResponse
    {
        $profile = $this->profile($request);

        $this->access->revoke($profile, $photo, $member_handle);

        Audit::log('performer_photo_access_revoked', $profile, ['photo_id' => $photo->id], $request);

        return response()->json([
            'requests' => $this->access->pendingRequestsFor($profile),
        ]);
    }

    /**
     * Serve os bytes de uma foto. PÚBLICO para foto pública; para foto PRIVADA,
     * só a dona ou quem tem grant aprovado — os demais recebem 404 (não 403), o
     * mesmo que um id inexistente, para o serving não confirmar a existência de
     * conteúdo privado. Quem decide é o PhotoAccessService (a MESMA regra do
     * presenter, § 2.3).
     *
     * Content-Type por re-sniff no servidor + `nosniff` (ServesPhotoBytes), nunca
     * uma URL de disco: o disco `performer_photos` roda `serve => false`.
     */
    public function image(Request $request, PerformerPhoto $photo): Response
    {
        abort_unless($this->access->canView($request->user(), $photo), 404);

        $bytes = $this->store->retrieve($photo->path);

        return $this->photoResponse($bytes, 'foto.jpg');
    }

    /**
     * A lista da galeria para o JSON do painel, do ponto de vista do ESPECTADOR
     * (aqui a própria dona: vê tudo, com o estado do cadeado por foto).
     *
     * @return array<int, array<string, mixed>>
     */
    private function payload(PerformerProfile $profile, ?User $viewer): array
    {
        // `unsetRelation` porque o service mexeu na galeria nesta mesma request;
        // sem isso a relação em cache devolveria a lista anterior.
        $profile->unsetRelation('photos');

        return PhotoGalleryPresenter::forProfile($profile, $viewer);
    }

    /**
     * O perfil da performer autenticada. 403 e não firstOrFail: conta de
     * performer sem perfil é onboarding incompleto — e a rota já exige
     * `can('performer-active')`, então este é defesa contra estado inconsistente.
     */
    private function profile(Request $request): PerformerProfile
    {
        $profile = $request->user()->performerProfile;

        abort_if($profile === null, 403);

        return $profile;
    }
}
