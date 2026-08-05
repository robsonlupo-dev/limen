<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Http\Resources\PerformerPublicResource;
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
        private PerformerCatalogService $catalog,
    ) {}

    /** Página da live. Live inexistente/encerrada (reconciliada) → 404. */
    public function show(Request $request, string $slug): Response
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);
        abort_if($session === null, 404);

        $bundle = $this->live->memberToken($session, $request->user());

        return Inertia::render('Live/Viewer', [
            'performer' => new PerformerPublicResource($performer),
            'token' => $bundle['token'],
            'wsUrl' => $bundle['wsUrl'],
            'viewerCount' => $this->live->viewerCount($session),
        ]);
    }

    /**
     * Renovação do JWT (a cada ~4 min, antes do TTL de 5). Reautoriza na leitura:
     * live encerrada → 410 Gone (o front desconecta). JSON explícito (fetch).
     */
    public function refresh(Request $request, string $slug): JsonResponse
    {
        $performer = $this->catalog->findPublicBySlug($slug);
        $session = $this->live->activeFor($performer);

        if ($session === null) {
            return response()->json(['message' => 'A live foi encerrada.'], 410);
        }

        return response()->json($this->live->memberToken($session, $request->user()));
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
