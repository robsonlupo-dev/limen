<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ReferralLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ReferralLinkRequest;
use App\Models\ReferralLink;
use App\Services\ReferralLinkService;
use App\Services\ReferralService;
use App\Support\SignupWelcomeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela "Indique e ganhe" (feat/referral-program → feat/referral-custom-links).
 * Agora no desenho aprovado estilo Airbnb: recompensas em tokens por papel,
 * compartilhar, QR com foto e até N links personalizados /r/{slug} (imutáveis).
 * Serve membro e performer igualmente.
 */
class ReferralController extends Controller
{
    public function __construct(
        private ReferralService $referrals,
        private ReferralLinkService $links,
    ) {}

    public function index(Request $request, SignupWelcomeData $welcome): Response
    {
        $user = $request->user();

        return Inertia::render('Referral/Index', [
            'enabled' => $this->referrals->enabled(),
            // Papel, bônus (do config), contadores e link padrão — a MESMA fonte
            // da tela de pós-cadastro, para as duas contarem a mesma história.
            'welcome' => $welcome->for($user),
            'custom_links' => ReferralLink::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (ReferralLink $l) => ['slug' => $l->slug, 'url' => $l->url()])
                ->values(),
            'custom_links_max' => (int) config('referral.custom_links_max', 2),
            // Foto para o centro do QR (opt-in no modal). Null → monograma do Portal.
            'avatar_url' => $user->avatarUrl(),
        ]);
    }

    /** Cria um link personalizado (POST por fetch → JSON explícito). */
    public function storeLink(ReferralLinkRequest $request): JsonResponse
    {
        try {
            $link = $this->links->create($request->user(), (string) $request->input('slug'), $request);
        } catch (ReferralLinkException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['slug' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['ok' => true, 'link' => ['slug' => $link->slug, 'url' => $link->url()]]);
    }

    /**
     * Rota pública /r/{slug}: a porta bonita da indicação. Resolve o dono, grava
     * o MESMO cookie do ?ref= (30 dias — RegisterController o lê no cadastro) e
     * manda para o destino da fase: pré-lançamento → home (lista de espera; a
     * ponte da lista + o cookie cobrem a atribuição), go-live → cadastro já com
     * o ref na URL. Slug desconhecido → home, sem cookie — a resposta externa é
     * igual nos dois casos (anti-oráculo de existência).
     */
    public function go(Request $request, string $slug): RedirectResponse
    {
        $owner = $this->links->resolveOwner($slug);

        if ($owner === null || ! $this->referrals->enabled()) {
            return redirect()->route('landing');
        }

        $code = $this->referrals->ensureCode($owner);

        Cookie::queue('referral_ref', $code, 60 * 24 * 30);

        if (config('features.landing_prelaunch')) {
            return redirect()->route('landing');
        }

        return redirect()->route('register', ['ref' => $code]);
    }
}
