<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\BroadcastMessage;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\TokenWallet;
use App\Services\BoostService;
use App\Services\PerformerStoryService;
use App\Services\StoryHighlightService;
use App\Support\StoryPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * As abas do Painel da performer além do Resumo (reorg do Painel, UAT Fase 21):
 * Stories · Canal · Ajustes. Cada uma é uma PÁGINA Inertia própria, como
 * Resumo e Insights já eram — as pílulas vêm de `lib/performerNav.js`.
 *
 * Antes tudo isto vivia numa rolagem única no Resumo, e o uso diário (publicar
 * story, transmitir) ficava no fim. Cada aba monta SÓ o que mostra, com a mesma
 * regra/presenter que o Resumo usava (StoryPresenter, BoostService…) — não há
 * uma segunda montagem do mesmo payload para divergir.
 *
 * Transição: o DashboardController ainda devolve estas props ao Resumo (os
 * testes de contrato apontam para lá); a "dieta de props" do Resumo é um PR
 * próprio, depois que esses testes migrarem para as abas.
 */
class PanelController extends Controller
{
    public function __construct(
        private PerformerStoryService $stories,
        private StoryHighlightService $highlights,
        private BoostService $boost,
    ) {}

    /** Aba Stories: publicar (no topo), os ativos, enquetes e os Destaques. */
    public function stories(Request $request): Response|RedirectResponse
    {
        $profile = $this->profileOrRedirect($request);
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return Inertia::render('Performer/Panel/Stories', [
            // Mesmo payload (e mesma dona, o StoryPresenter) que a seção recarrega
            // por `performer.stories.index` depois de publicar/apagar.
            'stories' => StoryPresenter::forOwner($profile, $this->stories),
            'storyVisibilityLevels' => PerformerStory::VISIBILITY_LEVELS,
            'storySubscriberMinTiers' => PerformerStory::SUBSCRIBER_MIN_TIERS,
            'storyInviteLimit' => PerformerStoryService::MAX_ACTIVE_INVITES,
            // Destaques (Onda 1): a mesma montagem da tela de editar perfil.
            'highlights' => $this->highlights->ownerView($profile),
            'highlightStories' => $this->stories->activeFor($profile)
                ->where('visibility_level', 'public')
                ->map(fn (PerformerStory $s) => ['id' => $s->id, 'url' => route('performer.stories.image', $s->id)])
                ->values()
                ->all(),
        ]);
    }

    /** Aba Canal: compor a transmissão + o que já foi enviado. */
    public function channel(Request $request): Response|RedirectResponse
    {
        $profile = $this->profileOrRedirect($request);
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return Inertia::render('Performer/Panel/Channel', [
            'broadcastMaxLength' => (int) config('broadcast.max_length', 1000),
            // Histórico do que ELA enviou. Só corpo e hora — a leitura por membro
            // não existe por desenho (a entrega é derivada de `follows` na
            // leitura; a performer não sabe quem leu).
            'broadcasts' => BroadcastMessage::query()
                ->where('performer_profile_id', $profile->id)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'body', 'created_at'])
                ->map(fn (BroadcastMessage $b) => [
                    'id' => $b->id,
                    'body' => $b->body,
                    'created_at' => $b->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /** Aba Ajustes: o que se configura uma vez — sons, destaque no catálogo, atalhos. */
    public function settings(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Como o Resumo: pendente (KYC em curso) também entra; suspensa, não.
        abort_unless(in_array($user->status, ['active', 'pending'], true), 403);

        $profile = $this->profileOrRedirect($request);
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return Inertia::render('Performer/Panel/Settings', [
            'wallet' => (int) (TokenWallet::where('user_id', $user->id)->value('balance') ?? 0),
            // Mesma montagem do Resumo (Sprint 11): booleano + faixa, nunca o
            // carimbo `boosted_until`; elegibilidade delegada ao BoostService.
            'boost' => [
                'is_boosted' => $profile->isBoosted(),
                'remaining_label' => $profile->boostRemainingLabel(),
                'cost' => $this->boost->cost(),
                'duration_hours' => $this->boost->durationHours(),
                'available_slots' => $this->boost->availableSlots(),
                'max_slots' => $this->boost->maxActive(),
                'eligible' => $this->boost->isEligible($profile),
            ],
        ]);
    }

    /** Perfil da performer, ou o redirect ao onboarding quando ela nunca o concluiu. */
    private function profileOrRedirect(Request $request): PerformerProfile|RedirectResponse
    {
        $profile = $request->user()->performerProfile;

        return $profile ?? redirect()->route('performer.onboarding');
    }
}
