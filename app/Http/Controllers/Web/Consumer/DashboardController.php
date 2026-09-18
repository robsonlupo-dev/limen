<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
use App\Models\Follow;
use App\Models\MemberPhoto;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\MemberPhotoService;
use App\Services\TokenService;
use App\Support\LedgerEntryLabel;
use App\Support\SpendRecipientResolver;
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

    private const SPENDS_PREVIEW = 5;

    public function __construct(
        private TokenService $tokenService,
        private MemberPhotoService $photos,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Consumer/Dashboard', [
            'balance' => $this->tokenService->balance($user),
            'following' => $this->following($request, $user),
            'followingCount' => $this->followingQuery($user)->count(),
            'interests' => $this->interestSummary($user),
            'tipsSummary' => $this->tipsSummary($user),
            // "Últimos gastos" (item 5): gastos de QUALQUER tipo (gorjeta, conteúdo,
            // chat…), não só gorjeta — o card agora combina com o "Ver extrato".
            'spends' => $this->recentSpends($user),
            // Fotos efêmeras ativas (Sprint 9B). Cada uma sai pelo apresentador
            // do model: id, faixa de tempo e o agregado "compartilhada com N
            // performers" do § 1.1. Nunca `expires_at`, nunca o TTL escolhido.
            'photos' => $this->photos->activeFor($user)
                ->map(fn (MemberPhoto $photo) => $photo->presentForMember())
                ->all(),
            'photoLimit' => MemberPhoto::ACTIVE_LIMIT,
            'photoTtlOptions' => $this->ttlOptions(),
        ]);
    }

    /**
     * O menu de TTL do § 1.2, montado no servidor.
     *
     * Vem daqui e não de uma lista escrita no Vue para que exista uma fonte só:
     * a constante do model é o que o `MemberPhotoService` valida, e um menu
     * divergente ofereceria ao membro um prazo que o servidor recusa.
     *
     * @return array<int, array{hours:int,label:string}>
     */
    private function ttlOptions(): array
    {
        $labels = [24 => '24 horas', 72 => '72 horas', 168 => '7 dias'];

        return array_map(
            fn (int $hours) => ['hours' => $hours, 'label' => $labels[$hours] ?? $hours.'h'],
            MemberPhoto::TTL_HOURS,
        );
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
     * Últimos GASTOS do membro (débitos do ledger), de qualquer tipo — gorjeta,
     * conteúdo, chat, presente, interesse. Cada um traz o tipo TRADUZIDO (nunca o
     * nome de banco), o destinatário (a performer — seguro, foi o próprio membro que
     * escolheu gastar com ela), o valor e a data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentSpends(User $user): array
    {
        $wallet = TokenWallet::where('user_id', $user->id)->first();

        if (! $wallet) {
            return [];
        }

        $entries = TokenLedger::where('wallet_id', $wallet->id)
            ->where('amount', '<', 0) // gastos = débitos
            ->orderByDesc('id')
            ->limit(self::SPENDS_PREVIEW)
            ->get();

        $recipients = (new SpendRecipientResolver)->resolve($entries);

        return $entries->map(fn (TokenLedger $e) => [
            'id' => $e->id,
            'label' => LedgerEntryLabel::for($e->entry_type),
            'recipient' => $recipients[$e->id] ?? null,
            'amount' => $e->amount, // "-2" (readable)
            'created_at' => $e->created_at?->format('d/m/Y H:i'),
        ])->all();
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
