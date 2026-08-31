<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\LiveChatException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendLiveChatRequest;
use App\Http\Resources\PerformerPublicResource;
use App\Models\TokenPackage;
use App\Services\LiveChatService;
use App\Services\LivePreviewService;
use App\Services\LiveSessionService;
use App\Services\PerformerCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistir à live pública GRÁTIS (Sprint 15, PR #139). Rota por SLUG da performer;
 * o room_name LiveKit nunca aparece aqui — só dentro do JWT que o
 * LiveSessionService devolve. Não cobra tokens (gorjeta/presente têm rotas
 * próprias). `findPublicBySlug` dá 404 para perfil não-público (paridade com o
 * catálogo). O gate de idade/KYC vem do grupo (role:consumer + member.verified).
 */
class LiveViewController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private LiveSessionService $live,
        private LiveChatService $chat,
        private PerformerCatalogService $catalog,
    ) {}

    /** Página da live. Live inexistente/encerrada (reconciliada) → 404. */
    public function show(Request $request, string $slug): Response
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);
        abort_if($session === null, 404);

        // Membro removido pela performer não reabre a sala.
        abort_if($this->chat->isMuted($session, $request->user()), 403);

        $bundle = $this->live->memberToken($session, $request->user());

        return Inertia::render('Live/Viewer', [
            // `->resolve()` para o prop chegar ao Vue como o OBJETO da performer
            // (slug, stage_name, …), NÃO embrulhado em `{ data: … }` — a mesma
            // convenção do CatalogController/PublicCatalogController. Sem isto,
            // `props.performer.slug` é undefined e todo POST da sala (gorjeta,
            // presente, chat) vai sem `performer_slug`.
            'performer' => (new PerformerPublicResource($performer))->resolve($request),
            'token' => $bundle['token'],
            'wsUrl' => $bundle['wsUrl'],
            'viewerCount' => $this->live->viewerCount($session),
            'initialChat' => $this->chat->recent($session),
            // A live pode estar PAUSADA (performer em chamada privada) — o viewer
            // mostra "volta já". O broadcast LiveStateChanged atualiza em tempo real;
            // isto cobre quem entra JÁ pausado.
            'paused' => $session->isPaused(),
            // Chamada privada A PARTIR da live (feat/private-call-from-live). O id do
            // perfil (o resource omite `id`) e o preço/min alimentam o botão "Pedir
            // chamada"; null = não aceita chamadas → o botão não aparece.
            'profileId' => $performer->id,
            'callPricePerMinute' => $performer->call_price_per_minute,
            'myUserId' => $request->user()->id,
            // Compra de tokens SOBRE a chamada (sem cair): pacotes + se falta CPF.
            // Reusa wallet.purchase/wallet.pending; nada de PII aqui além do já público.
            'tokenPackages' => $this->tokenPackages(),
            'needsCpf' => ! $request->user()->asaas_customer_id,
        ]);
    }

    /** Pacotes de tokens ativos, mesmo shape do WalletController, para o painel de compra. */
    private function tokenPackages(): array
    {
        return TokenPackage::where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TokenPackage $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'tokens' => $package->tokens,
                'bonus' => $package->bonus,
                'price_formatted' => 'R$ '.number_format($package->price_cents / 100, 2, ',', '.'),
            ])
            ->all();
    }

    /**
     * Renovação do JWT (a cada ~4 min, antes do TTL de 5). Reautoriza na leitura:
     * live encerrada → 410 Gone; membro removido → 403 (o front desconecta nos dois).
     * JSON explícito (fetch).
     */
    public function refresh(Request $request, string $slug): JsonResponse
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);

        if ($session === null) {
            return response()->json(['message' => 'A live foi encerrada.'], 410);
        }

        if ($this->chat->isMuted($session, $request->user())) {
            return response()->json(['message' => 'Você foi removido desta live.'], 403);
        }

        return response()->json($this->live->memberToken($session, $request->user()));
    }

    /**
     * Contagem AO VIVO de espectadores para o membro (polada ~20s). Mesma fonte
     * cacheada do console da performer; live encerrada → 410. Nunca em faixa: é
     * agregado de audiência, não exposição de indivíduo.
     */
    public function viewers(string $slug): JsonResponse
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);

        if ($session === null) {
            return response()->json(['message' => 'A live foi encerrada.'], 410);
        }

        // `paused` junto: um viewer que perdeu o broadcast LiveStateChanged reconcilia
        // o aviso "volta já" no próximo poll (~12s).
        return response()->json([
            'viewers' => $this->live->viewerCount($session),
            'paused' => $session->isPaused(),
        ]);
    }

    /** O membro fala no chat da sala. Free; passa pelo filtro; silenciado → 403. */
    public function sendChat(SendLiveChatRequest $request, string $slug): JsonResponse
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);

        if ($session === null) {
            return response()->json(['reason' => 'not_live', 'message' => 'A live foi encerrada.'], 410);
        }

        try {
            $message = $this->chat->send($session, $request->user(), false, $request->validated('body'));
        } catch (LiveChatException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(['id' => $message->id]);
    }

    /**
     * Último frame de preview do catálogo (PR #143). Por SLUG (o card tem o slug, o
     * resource não expõe o id) — resolve perfil público → live ATIVA → frame. 404
     * quando não há live, não há frame, ou o perfil não é público (paridade com o
     * show; nunca vaza se a performer existe/está no ar). Servido pela mesma
     * disciplina de bytes das outras imagens privadas (re-sniff + nosniff + inline
     * + no-store), atrás do gate de membro autenticado do grupo de rota.
     */
    public function preview(string $slug, LivePreviewService $previews): \Illuminate\Http\Response
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);
        abort_if($session === null, 404);

        $bytes = $previews->get($session);
        abort_if($bytes === null, 404);

        return $this->photoResponse($bytes, 'live.jpg');
    }
}
