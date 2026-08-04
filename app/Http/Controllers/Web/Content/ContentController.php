<?php

namespace App\Http\Controllers\Web\Content;

use App\Exceptions\ContentException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\PerformerContent;
use App\Services\ContentStore;
use App\Services\ContentUnlockService;
use App\Services\ContentVisibilityService;
use App\Support\ContentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContentController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(
        private ContentVisibilityService $visibility,
        private ContentUnlockService $unlocks,
        private ContentStore $store,
    ) {}

    /** Metadados da peça para ESTE espectador (locked/price/can_unlock). */
    public function show(Request $request, PerformerContent $content): JsonResponse
    {
        return response()->json(ContentPresenter::one($content, $request->user()));
    }

    /**
     * Bytes da peça. Autorização por request, sem URL assinada: quem não alcança
     * recebe 404 (uniforme — não confirma existência a quem não pode ver). Blur não
     * é paywall: peça bloqueada nunca chega a este ponto com URL.
     */
    public function image(Request $request, PerformerContent $content): Response
    {
        abort_unless($this->visibility->canView($request->user(), $content), 404);

        $bytes = $this->store->retrieve($content->path);

        return $this->photoResponse($bytes, 'conteudo.jpg');
    }

    /** Desbloqueio pago e permanente. Mapeia a recusa de domínio para HTTP. */
    public function unlock(Request $request, PerformerContent $content): JsonResponse
    {
        try {
            $unlock = $this->unlocks->unlock($request->user(), $content);
        } catch (ContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], match ($e->reason) {
                ContentException::OFFLINE => 404,
                ContentException::FORBIDDEN => 403,
                default => 422, // ALREADY / SELF
            });
        } catch (InsufficientBalanceException) {
            return response()->json([
                'message' => 'Saldo insuficiente para desbloquear este conteúdo.',
                'reason' => 'insufficient_balance',
            ], 422);
        }

        return response()->json([
            'message' => 'Conteúdo desbloqueado.',
            'content' => ContentPresenter::one($content->fresh(), $request->user()),
            'unlocked' => true,
            'tokens_paid' => (int) $unlock->tokens_paid,
        ]);
    }
}
