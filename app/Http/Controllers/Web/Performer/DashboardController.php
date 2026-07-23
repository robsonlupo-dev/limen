<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\FollowerVisibilityService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private ProfileVisitService $visits) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Sprint 7: pendente (KYC em curso) também entra — é quem o
        // KycPendingBanner existe para avisar. Suspensa segue 403; o corte de
        // role já veio do middleware `role:performer` da rota.
        abort_unless(in_array($user->status, ['active', 'pending'], true), 403);

        $profile = $user->performerProfile;

        // Conta criada mas onboarding nunca concluído (sem perfil): não há
        // painel para montar — devolve ao onboarding em vez de estourar.
        if (! $profile) {
            return redirect()->route('performer.onboarding');
        }
        $visitorPanel = $this->visits->panelFor($profile);

        return Inertia::render('Performer/Dashboard', [
            'wallet' => $this->walletBalance($user),
            'totalEarned' => $this->totalEarned($user),
            'tips' => $this->recentTips($profile),
            // Faixa também aqui: o observador que o contador exato entrega é a
            // PRÓPRIA performer. Faixar só as telas públicas esconderia o número
            // de terceiros e deixaria em pé exatamente a correlação que o Piso de
            // Anonimato existe para impedir — ela vendo 3 virar 4 ao vivo.
            'followers' => $profile->followersCountLabel(),
            'kycStatus' => $this->kycStatus($user),
            'isLive' => $profile->is_live,
            // Visitantes das últimas 24h, sob o mesmo FanAlias das gorjetas e
            // atrás do mesmo Piso de Anonimato da tela de seguidores (ver
            // ProfileVisitService::panelFor). Quem tem Ghost Mode (Black/FC) ou
            // Modo Discreto nunca gerou linha — para esta tela ele não passou
            // por aqui, e é isso que o perk vende.
            'visitors' => $visitorPanel['visitors'],
            'visitorsVisible' => $visitorPanel['visible'],
            'visitorsWindowHours' => ProfileVisitService::RECENT_HOURS,
            'anonymityFloor' => app(FollowerVisibilityService::class)->floor(),
        ]);
    }

    private function walletBalance(User $user): int
    {
        return TokenWallet::where('user_id', $user->id)->value('balance') ?? 0;
    }

    private function totalEarned(User $user): int
    {
        $walletId = TokenWallet::where('user_id', $user->id)->value('id');

        if (! $walletId) {
            return 0;
        }

        return (int) TokenLedger::where('wallet_id', $walletId)
            ->where('entry_type', 'tip_credit')
            ->sum('amount');
    }

    private function recentTips(PerformerProfile $profile): array
    {
        return Tip::where('performer_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['consumer_id', 'performer_amount', 'created_at'])
            // Pseudônimo por par (perfil, membro): `consumer_id % 10000` entregava
            // quatro dígitos do id real, e o mesmo espaço de ids fazia "Fã #2345"
            // casar com "Membro #12345" da lista de seguidores. Ver FanAlias.
            ->map(fn (Tip $tip) => [
                'fan' => FanAlias::label($profile->id, $tip->consumer_id),
                'amount' => $tip->performer_amount,
                'created_at' => $tip->created_at->format('d/m/Y H:i'),
            ])
            ->toArray();
    }

    private function kycStatus(User $user): string
    {
        $status = IdentityVerification::where('user_id', $user->id)
            ->latest()
            ->value('status');

        return match ($status) {
            'approved' => 'active',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }
}
