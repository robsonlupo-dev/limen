<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Exceptions\CustomOrderException;
use App\Http\Controllers\Concerns\ServesPhotoBytes;
use App\Http\Controllers\Controller;
use App\Models\CustomOrder;
use App\Models\PerformerContent;
use App\Services\ContentStore;
use App\Services\CustomOrderService;
use App\Support\Audit;
use App\Support\CustomOrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Resolução de DISPUTAS de encomenda sob medida (Onda 4 §4.3) — porta `/moderacao/*`,
 * sob `moderator.access` (moderator OU admin), como o resto da moderação.
 *
 * Uma encomenda ENTREGUE que o membro contesta dentro do prazo vira `disputed`: o
 * escrow fica RETIDO (nem libera à performer, nem estorna ao membro) e sai da varredura
 * automática — só um humano decide. Aqui o moderador vê as duas pontas pseudonimizadas
 * (performer pela vitrine, membro por FanAlias) e resolve a favor de um dos lados:
 *  • `release` → libera 80/20 à performer (a peça fica com o membro);
 *  • `refund`  → estorna 100% ao membro e revoga o acesso à peça.
 *
 * O dinheiro e a idempotência (escrow_settled, locks) vivem no CustomOrderService; aqui é
 * só a porta + o audit da AÇÃO do moderador. v1 minimal: sem UI rica de prova embutida — a
 * mídia entregue é servida pelos endpoints de conteúdo (que re-checam acesso), não na prop.
 */
class CustomOrderDisputeController extends Controller
{
    use ServesPhotoBytes;

    public function __construct(private CustomOrderService $orders) {}

    /** Fila das encomendas em disputa — as que aguardam decisão humana. */
    public function index(Request $request): Response
    {
        $orders = CustomOrder::where('status', CustomOrder::STATUS_DISPUTED)
            ->with(['performerProfile:id,stage_name,slug,user_id', 'deliveredContent'])
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (CustomOrder $o) => CustomOrderPresenter::forModeration($o));

        return Inertia::render('Moderacao/CustomOrders/Index', ['orders' => $orders]);
    }

    /**
     * Decide a disputa. `decision` é `release` (a favor da performer) ou `refund` (a
     * favor do membro). O service serializa read→check→settle sob lock e é idempotente
     * por escrow_settled — dois moderadores concorrentes nunca liberam E estornam.
     */
    public function resolve(Request $request, CustomOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:release,refund'],
        ]);

        try {
            $this->orders->resolveDispute($order, $validated['decision']);
        } catch (CustomOrderException $e) {
            return back()->with('error', "Encomenda #{$order->id}: {$e->getMessage()}");
        }

        // Audit da AÇÃO do moderador: registra a decisão e a encomenda, sem PII do
        // membro (o member_id fica na linha; o audit guarda só o id da encomenda).
        Audit::log('moderation.custom_order_resolved', $order, [
            'decision' => $validated['decision'],
        ]);

        $verbo = $validated['decision'] === 'release' ? 'liberada à performer' : 'estornada ao membro';

        return back()->with('success', "Encomenda #{$order->id} {$verbo}.");
    }

    /**
     * Serve a PROVA RETIDA de uma disputa: a foto entregue, ou o PÔSTER do vídeo (thumbnail
     * gerado pelo ffmpeg) — o que o moderador vê para decidir. Mesma disciplina dos
     * `moderacao.evidence.*`: bytes por endpoint dedicado e throttlado, Content-Type FIXO
     * (nós produzimos o JPEG), nunca na prop da página. Só serve enquanto a encomenda está
     * EM DISPUTA (reviewablePiece) — fora disso, 404 uniforme.
     */
    public function media(CustomOrder $order, ContentStore $store): HttpResponse
    {
        $piece = $this->reviewablePiece($order);

        // Foto → a própria imagem; vídeo → o pôster (o canView da moderação é o estado
        // DISPUTED + peça pronta, então o thumbnail do vídeo existe).
        $path = $piece->isVideo() ? $piece->thumbnail_path : $piece->path;
        abort_if($path === null || ! $store->exists($path), 404);

        return $this->photoResponse($store->retrieve($path), 'encomenda.jpg');
    }

    /**
     * Streama o VÍDEO entregue (MP4 já higienizado) para o moderador ASSISTIR à prova.
     * BinaryFileResponse com suporte a Range (seek), Content-Type fixo. Só em disputa aberta
     * e só se a peça for vídeo.
     */
    public function video(CustomOrder $order, ContentStore $store): BinaryFileResponse
    {
        $piece = $this->reviewablePiece($order);
        abort_unless($piece->isVideo() && $piece->path !== null && $store->exists($piece->path), 404);

        return response()->file($store->absolutePath($piece->path), [
            'Content-Type' => 'video/mp4',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="encomenda.mp4"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * A peça entregue que o moderador PODE rever: só quando a encomenda está EM DISPUTA e a
     * mídia está PRONTA. É o "canView" desta superfície — o moderador não é o membro nem a
     * performer, então o gate normal (ContentVisibilityService) recusaria; aqui o acesso é
     * ESCOPADO ao caso em julgamento e à porta `moderator.access`. Fora disso, 404 uniforme
     * (não confirma a existência da peça a quem não deveria alcançá-la).
     */
    private function reviewablePiece(CustomOrder $order): PerformerContent
    {
        abort_unless($order->isDisputed(), 404);

        $piece = $order->deliveredContent;
        abort_if($piece === null || ! $piece->isReady(), 404);

        return $piece;
    }
}
