<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\PerformerInterest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interesses enviados — o histórico da performer: para quem ela sinalizou, quem
 * revelou e quanto resta da cota do dia. Ver docs/INTEREST_SYSTEM_SPEC.md.
 *
 * Aqui a performer NÃO descobre ninguém: todo membro listado é alguém que ela
 * própria escolheu na lista de seguidores. Os membros seguem anonimizados
 * ("Membro #123", igual ao FollowersController) — a identidade real nunca sai
 * do servidor (CLAUDE.md, princípio 4).
 *
 * O que esta tela precisa esconder é o COMPORTAMENTO do membro, não o id dele:
 * quem optou por sair de interesses. É a tela que docs/INTEREST_ANONYMITY_FLOOR.md
 * antecipou ("armadilha do auto-unlock"), e a máscara vive em
 * PerformerInterest::scopeDisplayedAsUnlocked().
 */
class SentInterestsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;

        // Mesma saída do FollowersController: performer ativa sem perfil é
        // estado inconsistente, mas não derruba a página.
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        $interests = PerformerInterest::where('performer_profile_id', $profile->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Badge da lista e contador do topo saem os dois de displayedAsUnlocked()
        // — é a mesma regra avaliada duas vezes, e não duas regras. A distinção
        // importa: se divergissem ("2 revelados" com 3 badges), a inconsistência
        // sozinha já entregaria o opt-out.
        $unlockedIdsOnPage = PerformerInterest::where('performer_profile_id', $profile->id)
            ->whereIn('id', collect($interests->items())->pluck('id'))
            ->displayedAsUnlocked()
            ->pluck('id')
            ->flip();

        $totalUnlocked = PerformerInterest::where('performer_profile_id', $profile->id)
            ->displayedAsUnlocked()
            ->count();

        // Total de envios conta TODOS os status, suprimidos inclusive — é o
        // mesmo total que a performer deduz da cota diária que gastou.
        $totalSent = PerformerInterest::where('performer_profile_id', $profile->id)->count();

        $sentToday = PerformerInterest::where('performer_profile_id', $profile->id)
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();

        $dailyLimit = (int) config('interest.daily_limit');

        return Inertia::render('Performer/Interests', [
            'interests' => $interests->through(function (PerformerInterest $interest) use ($unlockedIdsOnPage) {
                $unlocked = $unlockedIdsOnPage->has($interest->id);

                return [
                    'id' => $interest->id,
                    // Sem member_id: a tela só rotula. A performer já tem o id
                    // em Seguidores, mas aqui ele não serve a nada.
                    'label' => 'Membro #' . $interest->member_id,
                    'sent_at' => $interest->sent_at->format('d/m/Y'),
                    // Só dois estados saem daqui: 'unlocked' ou 'sent'. Um
                    // suprimido cai em 'sent' (ou em 'unlocked', se tivesse
                    // auto-revelado) e fica indistinguível de um membro que
                    // simplesmente ainda não pagou.
                    'status' => $unlocked ? 'unlocked' : 'sent',
                    // Data real só existe para o desbloqueio real; no auto-revelado
                    // (mascarado ou não) a revelação é simultânea ao envio.
                    'unlocked_at' => $unlocked
                        ? ($interest->unlocked_at ?? $interest->sent_at)->format('d/m/Y')
                        : null,
                ];
            }),
            'stats' => [
                'total_sent' => $totalSent,
                'total_unlocked' => $totalUnlocked,
            ],
            'remainingToday' => max(0, $dailyLimit - $sentToday),
            'dailyLimit' => $dailyLimit,
        ]);
    }
}
