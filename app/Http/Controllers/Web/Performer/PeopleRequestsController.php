<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\MemberPhotoAccess;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Services\FollowerVisibilityService;
use App\Services\InterestService;
use App\Services\PhotoAccessService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pessoas › Solicitações (reorg do Painel, UAT Fase 21): a casa do que é
 * "gente" e pede AÇÃO dela — pedidos de acesso às fotos privadas (aprovar/
 * recusar), fotos que membros compartilharam, e os visitantes recentes.
 *
 * Tudo isto vivia no fim da rolagem do Resumo; o badge da nav ("Pessoas")
 * agora aponta para cá. As regras de privacidade são as MESMAS de sempre:
 * FanAlias em vez de id, faixa em vez de relógio, Piso de Anonimato nos
 * visitantes — ver cada service.
 *
 * Transição: o DashboardController ainda devolve estas props ao Resumo (os
 * testes de contrato apontam para lá); quando migrarem, os helpers de lá saem.
 */
class PeopleRequestsController extends Controller
{
    public function __construct(
        private ProfileVisitService $visits,
        private PhotoAccessService $photoAccess,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $profile = $request->user()->performerProfile;

        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        $visitorPanel = $this->visits->panelFor($profile);

        return Inertia::render('Performer/People/Requests', [
            // Pedidos de acesso às fotos PRIVADAS (Sprint 13), já pseudonimizados
            // (FanAlias label + handle). A seção recarrega por
            // `performer.gallery.requests` depois de aprovar/revogar.
            'photoAccessRequests' => $this->photoAccess->pendingRequestsFor($profile),
            // Fotos efêmeras que membros compartilharam com ela (Sprint 9B).
            'receivedPhotos' => $this->receivedPhotos($profile),
            // Visitantes das últimas 24h sob o Piso de Anonimato (ProfileVisitService).
            'visitors' => $visitorPanel['visitors'],
            'visitorsVisible' => $visitorPanel['visible'],
            'visitorsWindowHours' => ProfileVisitService::RECENT_HOURS,
            'canSendVisitorInterest' => (bool) $profile->is_verified,
            'visitorInterestRemaining' => $this->visitorInterestRemaining($profile),
            'anonymityFloor' => app(FollowerVisibilityService::class)->floor(),
        ]);
    }

    /**
     * Fotos vivas compartilhadas com esta performer — mesma montagem (e mesmas
     * omissões deliberadas: sem member_id, sem expires_at, sem viewed_at) do
     * Resumo. Ver o docblock original em DashboardController::receivedPhotos().
     *
     * @return array<int, array<string, mixed>>
     */
    private function receivedPhotos(PerformerProfile $profile): array
    {
        return MemberPhotoAccess::query()
            ->with('photo')
            ->where('performer_profile_id', $profile->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('granted_at')
            ->get()
            ->filter(fn (MemberPhotoAccess $access) => $access->photo !== null)
            ->map(fn (MemberPhotoAccess $access) => [
                'access_id' => $access->id,
                'fan' => FanAlias::label($profile->id, $access->photo->user_id),
                ...$access->presentForPerformer(),
            ])
            ->values()
            ->all();
    }

    /** Cota do dia da origem VISITANTES — conta o mesmo que o InterestService checa. */
    private function visitorInterestRemaining(PerformerProfile $profile): int
    {
        $limit = (int) config('interest.visitor_daily_limit');

        $sentToday = PerformerInterest::where('performer_profile_id', $profile->id)
            ->where('source', InterestService::SOURCE_VISITOR)
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $limit - $sentToday);
    }
}
