<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\ContentException;
use App\Http\Controllers\Controller;
use App\Models\PerformerContent;
use App\Services\ContentSaveService;
use App\Support\ContentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Salvos" do membro (roadmap social, Onda 3 §3.1) — bookmark PRIVADO de conteúdo.
 *
 * Vive em `Web\Consumer\` e nunca terá um irmão do lado da performer, como o
 * FavoriteController: salvar é área de membro, a performer não tem lado nenhum
 * nisto (não sabe que foi salva, não tem contador). Toda a regra vive no
 * ContentSaveService; aqui só a resolução do request e o mapeamento de erro.
 */
class ContentSaveController extends Controller
{
    public function __construct(private ContentSaveService $saves) {}

    /**
     * A lista salva pelo membro (todas as performers). Mesmo item do feed de
     * conteúdo (`feedItem`), resolvido POR ESTE membro (mesmo paywall) — cada
     * linha aqui é, por definição, salva, então `saved` vai explícito como true.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $paginator = $this->saves->paginateFor($viewer);

        return Inertia::render('Consumer/Saved/Index', [
            // Mesma forma do feed (data/current_page/last_page/has_more) para reusar
            // o "carregar mais". Cada linha é, por definição, salva → `saved` true.
            'saved' => [
                'data' => collect($paginator->items())
                    ->map(fn (PerformerContent $content) => array_merge(
                        ContentPresenter::feedItem($content, $viewer),
                        ['saved' => true],
                    ))
                    ->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Um clique no bookmark: salva se não estava, remove se estava. `on` (bool) no
     * corpo. Salvar exige poder VER a peça (a service confere → 403 forbidden);
     * desalvar é sempre permitido. Sem flash — o próprio ícone é o retorno, e a
     * feature existe para ser discreta (mesma razão do coração do FavoriteController).
     */
    public function toggle(Request $request, PerformerContent $content): JsonResponse
    {
        $on = $request->boolean('on');

        try {
            $saved = $this->saves->setSaved($request->user(), $content, $on);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], match ($e->reason) {
                ContentException::FORBIDDEN => 403, // não se salva o que não se pode ver
                default => 404,
            });
        }

        return response()->json(['saved' => $saved]);
    }
}
