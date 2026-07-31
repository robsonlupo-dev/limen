<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\ImageProcessingException;
use App\Exceptions\PhotoGalleryException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ReorderPerformerPhotosRequest;
use App\Http\Requests\Web\StorePerformerPhotoRequest;
use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Services\PerformerPhotoService;
use App\Services\PerformerPhotoStore;
use App\Support\Audit;
use App\Support\PhotoGalleryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Galeria de fotos do perfil da performer (Sprint 10).
 *
 * O controller só delega — cap de 6, propriedade e ordem vivem no
 * `PerformerPhotoService`. A tradução para HTTP é responsabilidade daqui, e não é
 * automática: o front fala com rotas WEB, onde uma exceção não vira JSON sozinha
 * (só em `api/*`). Sem o `response()->json()` explícito o Vue receberia HTML.
 *
 * `image()` é a única ação PÚBLICA: a galeria é do perfil público, então qualquer
 * visitante vê os bytes. As três de gestão (store/destroy/reorder) ficam atrás de
 * auth + role:performer + 2fa + documents.accepted + performer-active, como toda
 * rota nova da performer (CLAUDE.md).
 */
class PhotoController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private PerformerPhotoService $photos,
        private PerformerPhotoStore $store,
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
        }

        Audit::log('performer_photo_added', $profile, ['photo_id' => $photo->id], $request);

        return response()->json([
            'photos' => $this->payload($profile),
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
            'photos' => $this->payload($profile),
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
            'photos' => $this->payload($profile),
        ]);
    }

    /**
     * Serve os bytes de uma foto. PÚBLICO — é o perfil público.
     *
     * Content-Type por re-sniff no servidor + `nosniff` (ServesPhotoBytes), nunca
     * uma URL de disco: o disco `performer_photos` roda `serve => false`. A foto é
     * conteúdo público, então não há autorização por espectador — mas continua
     * passando pela camada de bytes, que é o que mata o polyglot servido.
     */
    public function image(PerformerPhoto $photo): Response
    {
        $bytes = $this->store->retrieve($photo->path);

        return $this->photoResponse($bytes, 'foto.jpg');
    }

    /**
     * A lista da galeria para o JSON do painel: id, url pública e posição.
     *
     * @return array<int, array{id:int,url:string,position:int}>
     */
    private function payload(PerformerProfile $profile): array
    {
        // `unsetRelation` porque o service mexeu na galeria nesta mesma request;
        // sem isso a relação em cache devolveria a lista anterior.
        $profile->unsetRelation('photos');

        return PhotoGalleryPresenter::forProfile($profile);
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
