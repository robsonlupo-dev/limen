<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Services\FollowerVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seguidores da performer — é daqui que ela dispara o Interesse Controlado
 * (ver docs/INTEREST_SYSTEM_SPEC.md). Escolhemos os seguidores como origem
 * porque o membro já se expôs à performer ao segui-la: nenhuma superfície nova
 * de descoberta de membros é criada.
 *
 * Os membros aparecem anonimizados ("Membro #123"). A performer só precisa do
 * id para enviar o interesse, e o id já vai no POST de qualquer forma — nome e
 * e-mail nunca são expostos (CLAUDE.md, princípio 4).
 */
class FollowersController extends Controller
{
    public function __construct(private FollowerVisibilityService $visibility) {}

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;

        // Performer ativa sem perfil é um estado inconsistente, mas não deve
        // derrubar a página — manda completar o onboarding.
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }

        // Só membros que ainda existem e estão ativos. Sem este filtro a lista
        // mostrava suspensos e contas apagadas (whereHas respeita o SoftDeletes
        // do User), o que (a) mantinha quem apagou a conta visível à performer e
        // (b) virava oráculo de status: o envio para esse id dá 404, enquanto um
        // seguidor normal dá 201/422 — clicar no botão revelava a suspensão.
        // Todo id listado aqui precisa resolver em SendInterestRequest.
        // Piso de Anonimato + Modo Discreto vivem no FollowerVisibilityService,
        // que é a MESMA fonte usada por SendInterestRequest: se a tela e o envio
        // discordarem, o envio vira oráculo para reconstruir o que a tela esconde.
        $totalFollowers = $this->visibility->totalActiveFollowers($profile->id);
        $belowFloor = ! $this->visibility->canRevealList($profile->id);

        $query = $this->visibility->listableQuery($profile->id);

        if ($belowFloor) {
            // Paginação vazia, mantendo o formato que a página espera.
            $query->whereRaw('1 = 0');
        }

        // Desempate por id: created_at tem granularidade de segundo, e follows
        // do mesmo segundo ficariam em ordem indefinida — o que, paginado, faz
        // linha repetir numa página e sumir de outra.
        $follows = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        $cooldownDays = (int) config('interest.cooldown_days');

        // Membros desta página que já receberam interesse desta performer dentro
        // da janela de cooldown. Conta interesses suprimidos (opt-out) também —
        // é justamente o que impede a lista de revelar quem optou por sair.
        $inCooldown = PerformerInterest::where('performer_profile_id', $profile->id)
            ->whereIn('member_id', collect($follows->items())->pluck('user_id'))
            ->where('sent_at', '>=', now()->subDays($cooldownDays))
            ->pluck('member_id')
            ->all();

        $sentToday = PerformerInterest::where('performer_profile_id', $profile->id)
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();

        $dailyLimit = (int) config('interest.daily_limit');

        return Inertia::render('Performer/Followers', [
            'followers' => $follows->through(fn (Follow $follow) => [
                'member_id' => $follow->user_id,
                'label' => 'Membro #' . $follow->user_id,
                'following_since' => $follow->created_at->format('d/m/Y'),
                'interest_sent' => in_array($follow->user_id, $inCooldown, true),
            ]),
            'remainingToday' => max(0, $dailyLimit - $sentToday),
            'dailyLimit' => $dailyLimit,
            'cooldownDays' => $cooldownDays,
            'below_floor' => $belowFloor,
            'total_followers' => $totalFollowers,
            'floor_message' => $belowFloor
                ? 'Para proteger o anonimato dos membros Limen, a lista de seguidores fica visível a partir de '
                    . config('interest.anonymity_floor') . ' seguidores.'
                : null,
        ]);
    }
}
