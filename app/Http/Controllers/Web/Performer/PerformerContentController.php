<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\ContentException;
use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\VideoProcessingException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PublishContentRequest;
use App\Models\PerformerContent;
use App\Services\ContentStore;
use App\Services\ContentVisibilityService;
use App\Services\PerformerContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PerformerContentController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private PerformerContentService $content,
        private ContentStore $store,
        private ContentVisibilityService $visibility,
    ) {}

    /**
     * Tela de gestão de conteúdo permanente (UAT fix). O backend do Sprint 14
     * existia ponta a ponta (store/index/destroy), mas sem página nem link — a
     * performer não tinha por onde publicar. A tela consome o `index` (JSON) por
     * fetch no mount, no mesmo molde da galeria/stories.
     */
    public function page(Request $request): InertiaResponse
    {
        Gate::authorize('performer-active');

        return Inertia::render('Performer/Content/Index', [
            // Estado inicial (a tela recarrega por fetch após publicar/apagar).
            'content' => $this->content->forOwner($request->user()->performerProfile),
            'levels' => PerformerContent::LEVELS,
            'minPrice' => 5,
        ]);
    }

    /** As peças da própria performer + contagem de desbloqueios. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('performer-active');

        return response()->json([
            'content' => $this->content->forOwner($request->user()->performerProfile),
        ]);
    }

    public function store(PublishContentRequest $request): JsonResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        $file = $request->file('arquivo');
        $level = $request->validated('nivel');
        $price = (int) $request->validated('preco');

        try {
            // Vídeo → pipeline assíncrono (ffmpeg); foto → síncrono (GD).
            $piece = $request->isVideoUpload()
                ? $this->content->publishVideo($profile, $file, $level, $price)
                : $this->content->publish($profile, $file, $level, $price);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (ImageProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'invalid_image'], 422);
        } catch (CsamDetectedException $e) {
            // Mensagem genérica (não revela o motivo real). O CRITICAL/flag já
            // aconteceram no CsamScanService.
            return response()->json(['message' => $e->getMessage(), 'reason' => 'rejected'], 422);
        } catch (VideoProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'message' => $piece->isVideo()
                ? 'Vídeo recebido. Ele ficará disponível após o processamento.'
                : 'Conteúdo publicado.',
            'id' => $piece->id,
            'kind' => $piece->kind,
            'status' => $piece->status,
            'access_level' => $piece->access_level,
            'price_tokens' => $piece->price_tokens,
        ], 201);
    }

    public function destroy(Request $request, PerformerContent $content): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $this->content->remove($request->user()->performerProfile, $content);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], match ($e->reason) {
                ContentException::UNDER_REVIEW => 409,
                default => 404, // OFFLINE (não é dela)
            });
        }

        return response()->json(['message' => 'Conteúdo removido.']);
    }

    /** Quem desbloqueou — só por FanAlias (M.13.10); fc_only revela FC. */
    public function unlockers(Request $request, PerformerContent $content): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $unlockers = $this->content->unlockersFor($request->user()->performerProfile, $content);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 404);
        }

        return response()->json(['unlockers' => $unlockers]);
    }

    /**
     * Serving da própria peça para a performer (preview de gestão). A dona sempre
     * vê (canView isenta a dona), mas mantém a passagem pela camada de bytes.
     */
    public function image(Request $request, PerformerContent $content): Response
    {
        Gate::authorize('performer-active');

        abort_unless($this->visibility->canView($request->user(), $content), 404);

        // Vídeo pronto → o POSTER (thumbnail); foto → a própria imagem.
        $path = $content->isVideo() ? $content->thumbnail_path : $content->path;

        return $this->photoResponse($this->store->retrieve($path), 'conteudo.jpg');
    }
}
