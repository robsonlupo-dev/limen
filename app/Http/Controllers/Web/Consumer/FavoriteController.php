<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Services\FavoriteService;
use App\Services\PerformerCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Favoritos do membro (Sprint 10) — bookmark privado.
 *
 * Vive em `Web\Consumer\` e não ao lado do FollowController de propósito: o
 * follow é uma superfície COMPARTILHADA (a performer lê a outra ponta), o
 * favorito é área de membro e nunca terá um controller irmão do lado dela.
 *
 * Toda a regra está no FavoriteService. Aqui só entra a resolução do slug —
 * pela mesma `findBySlug()` do catálogo, para que favoritar um perfil fora do
 * ar 404 exatamente como abri-lo, e o par de respostas não vire oráculo de
 * existência.
 */
class FavoriteController extends Controller
{
    public function __construct(
        private FavoriteService $favorites,
        private PerformerCatalogService $catalogService,
    ) {}

    /**
     * A lista salva pelo membro. Mesmos dados do catálogo: o card é o mesmo.
     */
    public function index(Request $request): Response
    {
        $performers = $this->favorites->paginateFor($request->user());

        $paginated = PerformerPublicResource::collection($performers)->response()->getData(true);

        // Toda linha desta tela é, por definição, favoritada — a página é a
        // lista. O flag vai explícito assim mesmo porque o PerformerCard é o
        // mesmo do catálogo e desenha o coração a partir dele; derivar "está
        // preenchido" de "está nesta página" acoplaria o componente à tela.
        $paginated['data'] = collect($paginated['data'])
            ->map(fn ($item) => array_merge($item, ['is_favorited' => true]))
            ->all();

        return Inertia::render('Consumer/Favorites/Index', [
            'performers' => $paginated,
        ]);
    }

    /**
     * Um clique no coração: salva se não estava salvo, remove se estava.
     *
     * Sem flash de sucesso, ao contrário do FollowController: o coração fica num
     * card de grid e o próprio estado dele é o retorno visual. Uma barra verde
     * no topo a cada clique numa grade de 20 cards é ruído — e, mais do que
     * isso, o texto anunciaria na tela uma ação que a feature existe para manter
     * discreta.
     */
    public function toggle(Request $request, string $slug): RedirectResponse
    {
        $profile = $this->catalogService->findBySlug($slug);

        $this->favorites->toggle($request->user(), $profile);

        return back();
    }
}
