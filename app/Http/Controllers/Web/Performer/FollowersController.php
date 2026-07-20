<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Services\FollowerVisibilityService;
use App\Support\FanAlias;
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
 * Os membros aparecem sob pseudônimo ("Membro #7351", ver App\Support\FanAlias):
 * derivado por par (perfil, membro), então não é o id e não correlaciona com o
 * "Fã #" das gorjetas. Nem o id sai daqui — o que vai para a tela e volta no
 * POST é o handle opaco. Nome e e-mail nunca são expostos (CLAUDE.md, § 4).
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

        // Quem é listável (membro ativo, não-discreto, piso atingido) vive no
        // FollowerVisibilityService, que é a MESMA fonte usada por
        // SendInterestRequest: se a tela e o envio discordarem, o envio vira
        // oráculo para reconstruir exatamente o que a tela esconde.
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
                // Handle opaco, não o id: é ele que volta no POST do Interesse.
                // Trocar só o `label` teria sido maquiagem — o id cru continuaria
                // legível nas props do Inertia, que é de onde a performer leria.
                'member_handle' => FanAlias::handle($profile->id, $follow->user_id),
                'label' => FanAlias::label($profile->id, $follow->user_id, 'Membro #'),
                'following_since' => $follow->created_at->format('d/m/Y'),
                'interest_sent' => in_array($follow->user_id, $inCooldown, true),
            ]),
            'remainingToday' => max(0, $dailyLimit - $sentToday),
            'dailyLimit' => $dailyLimit,
            'cooldownDays' => $cooldownDays,
            'below_floor' => $belowFloor,
            // Faixa, não o número: o raw fica no servidor (é ele que decide o
            // piso). Mandá-lo para a tela devolveria à performer o contador
            // preciso que as faixas existem para tirar — ela veria "3" virar "4"
            // no instante em que alguém seguisse. Rotula os seguidores ativos,
            // não o contador denormalizado, senão a tela diria "10+" enquanto o
            // piso enxerga 3 e esconde a lista.
            //
            // Aqui contam TODOS os ativos, inclusive contas novas — a faixa é
            // exibição. Quem destrava a lista é a contagem com corte de idade
            // (canRevealList), então "5+" com a lista escondida é um estado
            // legítimo: são seguidores demais recentes para diluir alguém.
            'total_followers_label' => PerformerProfile::followersLabelFor($totalFollowers),
            'floor_message' => $belowFloor
                ? 'Para proteger o anonimato dos membros Limen, a lista de seguidores fica visível a partir de '
                    .config('interest.anonymity_floor').' seguidores.'
                : null,
        ]);
    }
}
