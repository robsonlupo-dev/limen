<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\MemberPhotoAccess;
use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;
use App\Models\Tip;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\FollowerVisibilityService;
use App\Services\InterestService;
use App\Services\PerformerStoryService;
use App\Services\ProfileVisitService;
use App\Support\FanAlias;
use App\Support\LifestyleTier;
use App\Support\StoryPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private ProfileVisitService $visits,
        private PerformerStoryService $stories,
    ) {}

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
            // Interesse a partir do painel (Sprint 9). Só performer VERIFICADA
            // envia por esta via — o botão nem renderiza para as demais, e o
            // Form Request recusa de qualquer forma (a tela não é o guard).
            'canSendVisitorInterest' => (bool) $profile->is_verified,
            // Cota do dia da origem VISITANTES, separada da de seguidores. Vai
            // para a tela só para o botão poder se desabilitar antes do 422;
            // quem enforça é o InterestService, dentro da transação travada.
            'visitorInterestRemaining' => $this->visitorInterestRemaining($profile),
            'anonymityFloor' => app(FollowerVisibilityService::class)->floor(),
            // Fotos efêmeras que membros compartilharam com ela (Sprint 9B).
            'receivedPhotos' => $this->receivedPhotos($profile),
            // Stories vivos dela (Sprint 9C). O payload vem do StoryPresenter, que
            // é a dona dele: a seção também recarrega por `performer.stories.index`
            // depois de publicar/apagar, e duas montagens do mesmo card
            // divergiriam — provavelmente na faixa, que é o que não pode virar
            // número (§ 2.2, decisão nº 3).
            'stories' => StoryPresenter::forOwner($profile, $this->stories),
            'storyVisibilityLevels' => PerformerStory::VISIBILITY_LEVELS,
            // A performer PENDENTE alcança este painel de propósito (Sprint 7),
            // mas as rotas de story exigem `can('performer-active')`. A tela não é
            // o guard — ela só evita oferecer um botão que responderia 403.
            'canPublishStories' => $user->status === 'active',
        ]);
    }

    /**
     * Fotos vivas compartilhadas com esta performer.
     *
     * ── O que sai, e o que NÃO sai ──────────────────────────────────────────
     * Sai: o `FanAlias` do membro, a FAIXA de tempo restante e o id do ACESSO
     * (que é a chave do endpoint de leitura). Não sai, e nenhum destes é
     * esquecimento:
     *  - `member_id`: é a regra do FanAlias — o rosto já quebra o isolamento
     *    cross-perfil (§ 1.1), e não há razão para somar o id cru a isso;
     *  - `expires_at` e o TTL escolhido: § 1.2, o relógio devolve `granted_at`
     *    ao minuto e o TTL é postura do membro;
     *  - `viewed_at`: § 1.8, é a ação dela e não volta em hipótese alguma.
     *
     * O par (alias, faixa) vem do apresentador do model, não montado aqui: a
     * segunda tela que montasse o payload à mão nasceria carregando o id cru.
     *
     * Ordena por `granted_at` desc — a ordem de chegada é informação legítima
     * dela, ao contrário do painel de visitantes, onde a POSIÇÃO dentro da faixa
     * entregava o que o relógio entregava (item 13 do CLAUDE.md). Aqui não há
     * pseudônimo a proteger da própria conversa: o membro escolheu se mostrar.
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
            // A foto pode ter sido revogada pelo membro sem que o acesso tenha
            // saído junto (revoke fora do GC): sem linha de foto não há o que
            // servir, e o card levaria a um 404.
            ->filter(fn (MemberPhotoAccess $access) => $access->photo !== null)
            ->map(fn (MemberPhotoAccess $access) => [
                'access_id' => $access->id,
                'fan' => FanAlias::label($profile->id, $access->photo->user_id),
                ...$access->presentForPerformer(),
            ])
            ->values()
            ->all();
    }

    /**
     * Quantos interesses a performer ainda pode enviar HOJE pela origem
     * visitantes. Conta a mesma coisa que o InterestService checa — o filtro
     * por `source` é o que mantém as duas cotas independentes.
     */
    private function visitorInterestRemaining(PerformerProfile $profile): int
    {
        $limit = (int) config('interest.visitor_daily_limit');

        $sentToday = PerformerInterest::where('performer_profile_id', $profile->id)
            ->where('source', InterestService::SOURCE_VISITOR)
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $limit - $sentToday);
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
        $tips = Tip::where('performer_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['consumer_id', 'performer_amount', 'created_at']);

        // Estilo de Vida (Sprint 10), em lote e como rótulo. Quem não declarou
        // vem `null` e a tela não desenha nada — ver LifestyleTier::labelFor().
        $lifestyleLabels = LifestyleTier::labelsFor($tips->pluck('consumer_id')->all());

        return $tips
            // Pseudônimo por par (perfil, membro): `consumer_id % 10000` entregava
            // quatro dígitos do id real, e o mesmo espaço de ids fazia "Fã #2345"
            // casar com "Membro #12345" da lista de seguidores. Ver FanAlias.
            ->map(fn (Tip $tip) => [
                'fan' => FanAlias::label($profile->id, $tip->consumer_id),
                'lifestyle' => $lifestyleLabels[$tip->consumer_id] ?? null,
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
