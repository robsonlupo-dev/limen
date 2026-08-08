<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Services\ContentVisibilityService;
use App\Support\ContentPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Feed/timeline de conteúdo permanente do membro (Sprint 16, consumidor do
 * PR #135): as peças das performers que ele SEGUE, mais recentes primeiro.
 *
 * Não cria endpoint de dados novo além desta página: o desbloqueio e o serving
 * dos bytes reusam as rotas `content.unlock`/`content.image` (as mesmas da
 * galeria do perfil), e o paywall é do ContentVisibilityService — dona única.
 *
 * Dois cortes, ambos no SQL, ANTES de qualquer conteúdo:
 *  1. `publicCatalog()`: só performer DE PÉ (ativa, verificada, não trashed) —
 *     banida/suspensa cai fora, como na lista "Seguindo" do painel.
 *  2. `allowedLevelsFor`: só os níveis que o TIER do membro alcança (M.13.13),
 *     para a paginação contar certo (filtrar por tier após o paginate daria
 *     páginas curtas). O bloqueio de PAGAMENTO segue por item (locked/can_unlock
 *     do presenter): o membro vê a peça do nível que alcança, paga para abrir.
 */
class FeedController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(private ContentVisibilityService $visibility) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Performers seguidas E de pé. `publicCatalog` já exclui banida/suspensa/
        // não-verificada/trashed — o corte de reachability do § do serving, no SQL.
        $followedIds = PerformerProfile::publicCatalog()
            ->whereIn('id', Follow::where('user_id', $user->id)->select('performer_profile_id'))
            ->pluck('id');

        $paginator = PerformerContent::query()
            ->whereIn('performer_profile_id', $followedIds)
            ->ready() // vídeo em processing/failed fica fora do feed
            ->whereIn('access_level', $this->visibility->allowedLevelsFor($user))
            ->with('performerProfile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('Consumer/Feed', [
            'feed' => [
                'data' => collect($paginator->items())
                    ->map(fn (PerformerContent $content) => ContentPresenter::feedItem($content, $user))
                    ->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            // Distingue "não segue ninguém" (CTA para o catálogo) de "segue, mas
            // ninguém publicou ainda" — dois estados vazios com a mesma saída.
            'followsAnyone' => Follow::where('user_id', $user->id)->exists(),
        ]);
    }
}
