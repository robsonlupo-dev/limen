<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Exceptions\CustomOrderException;
use App\Http\Controllers\Controller;
use App\Models\CustomOrder;
use App\Services\CustomOrderService;
use App\Support\Audit;
use App\Support\CustomOrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
}
