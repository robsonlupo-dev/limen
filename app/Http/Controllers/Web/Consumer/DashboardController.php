<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Follow;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel do membro: saldo, quem ele segue, interesses recebidos e gorjetas
 * enviadas. É a home da área logada do consumer.
 */
class DashboardController extends Controller
{
    private const FOLLOWING_PREVIEW = 6;
    private const TIPS_PREVIEW = 5;

    public function __construct(private TokenService $tokenService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Consumer/Dashboard', [
            'balance' => $this->tokenService->balance($user),
            'following' => $this->following($request, $user),
            'followingCount' => $this->followingQuery($user)->count(),
            'interests' => $this->interestSummary($user),
            'tips' => $this->recentTips($user),
            'tipsSummary' => $this->tipsSummary($user),
        ]);
    }

    /**
     * Performers seguidas que estão no ar. Contagem e lista saem DAQUI, do mesmo
     * escopo: contar todos os follows e listar só os públicos fazia a diferença
     * entre os dois números denunciar que alguém que o membro segue foi suspensa
     * ou desverificada.
     */
    private function followingQuery(User $user): Builder
    {
        return PerformerProfile::publicCatalog()
            ->whereIn('id', Follow::where('user_id', $user->id)->select('performer_profile_id'));
    }

    /**
     * Prévia de quem o membro segue. Quem saiu do ar não vira card que leva a
     * um 404 — ver followingQuery().
     *
     * @return array<int, array<string, mixed>>
     */
    private function following(Request $request, User $user): array
    {
        $profiles = $this->followingQuery($user)
            ->orderByDesc('is_live')
            ->orderByDesc('followers_count')
            ->limit(self::FOLLOWING_PREVIEW)
            ->get();

        return PerformerPublicResource::collection($profiles)->resolve($request);
    }

    /**
     * Só contagens — nenhuma identidade de performer. Um interesse bloqueado
     * não pode revelar quem enviou antes do pagamento, e manter a revelação
     * confinada à caixa (/interesses) deixa uma única tela responsável por
     * aplicar essa máscara.
     *
     * @return array<string, int>
     */
    private function interestSummary(User $user): array
    {
        $counts = PerformerInterest::where('member_id', $user->id)
            ->visibleToMember()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'locked' => (int) ($counts['sent'] ?? 0),
            'unlocked' => (int) ($counts['unlocked'] ?? 0),
        ];
    }

    /**
     * Gorjetas enviadas. Revelar a performer aqui é seguro: foi o próprio
     * membro que escolheu mandar a gorjeta para ela.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentTips(User $user): array
    {
        return Tip::where('consumer_id', $user->id)
            ->with('performerProfile:id,stage_name')
            ->orderByDesc('id')
            ->limit(self::TIPS_PREVIEW)
            ->get()
            ->map(fn (Tip $tip) => [
                'id' => $tip->id,
                'performer' => $tip->performerProfile?->stage_name,
                'amount' => $tip->amount,
                'created_at' => $tip->created_at?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function tipsSummary(User $user): array
    {
        $tips = Tip::where('consumer_id', $user->id);

        return [
            'count' => (clone $tips)->count(),
            'tokens' => (int) $tips->sum('amount'),
        ];
    }
}
