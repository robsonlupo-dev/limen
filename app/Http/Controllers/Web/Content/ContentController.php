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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        // Foto → a própria imagem; vídeo → o POSTER (thumbnail gerado pelo ffmpeg).
        // O canView já garante READY, então o thumbnail existe para vídeo.
        $path = $content->isVideo() ? $content->thumbnail_path : $content->path;

        // Bytes ausentes no disco (arquivo não sincronizado entre clones, thumbnail
        // não gerado, upload/processamento que falhou): 404 DEFINIDO, não o 500 que
        // `retrieve()` lançaria. O front trata o 404 com um placeholder decente
        // (fix/chat-ux-mobile) em vez de deixar a <img> quebrar silenciosamente.
        abort_if($path === null || ! $this->store->exists($path), 404);

        return $this->photoResponse($this->store->retrieve($path), 'conteudo.jpg');
    }

    /**
     * Prévia BORRADA do tile bloqueado (feat/content-showcase, item 7). NÃO exige
     * canView — é a isca para quem NÃO pode ver o original. Segura porque é
     * irreversível (baixa resolução + blur pesado, gerada no servidor); jamais a
     * imagem original. Só de peça de pé (ready + performer alcançável). Sem blur no
     * disco → 404, e o front cai no placeholder.
     */
    public function blur(Request $request, PerformerContent $content): Response
    {
        abort_unless(
            $content->isReady() && $this->visibility->performerIsReachable($content->performerProfile),
            404,
        );

        $source = $content->isVideo() ? $content->thumbnail_path : $content->path;
        abort_if($source === null, 404);

        $blurPath = $this->store->blurPathFor($source);
        abort_unless($this->store->exists($blurPath), 404);

        return $this->photoResponse($this->store->retrieve($blurPath), 'preview.jpg');
    }

    /**
     * Bytes do VÍDEO (MP4 já higienizado). BinaryFileResponse streama do disco
     * local com suporte a Range (seek do player), sem carregar tudo em memória.
     * Content-Type FIXO video/mp4 (nós controlamos os bytes) + nosniff.
     */
    public function video(Request $request, PerformerContent $content): BinaryFileResponse
    {
        abort_unless($content->isVideo() && $this->visibility->canView($request->user(), $content), 404);

        return response()->file($this->store->absolutePath($content->path), [
            'Content-Type' => 'video/mp4',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="conteudo.mp4"',
        ]);
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
