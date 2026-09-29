<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\CustomOrderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreCustomOrderRequest;
use App\Models\CustomOrder;
use App\Models\PerformerProfile;
use App\Services\CustomOrderService;
use App\Services\TokenService;
use App\Support\CustomOrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encomendas sob medida — lado do MEMBRO (Onda 4 §4.3). Pedir, cancelar (antes do
 * aceite), aprovar/contestar a entrega, e listar as próprias. A regra e o escrow vivem
 * no CustomOrderService; aqui é só a porta (rotas web não viram JSON sozinhas).
 */
class CustomOrderController extends Controller
{
    public function __construct(
        private CustomOrderService $orders,
        private TokenService $tokenService,
    ) {}

    public function index(Request $request): Response
    {
        $orders = CustomOrder::where('member_id', $request->user()->id)
            ->with(['performerProfile:id,stage_name,slug', 'deliveredContent'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CustomOrder $o) => CustomOrderPresenter::forMember($o));

        return Inertia::render('Consumer/CustomOrders/Index', [
            'orders' => $orders,
            'balance' => $this->tokenService->balance($request->user()),
        ]);
    }

    public function store(StoreCustomOrderRequest $request, PerformerProfile $profile): JsonResponse
    {
        try {
            $order = $this->orders->request(
                $request->user(),
                $profile,
                $request->validated('description'),
                (int) $request->validated('price_tokens'),
            );
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['order_id' => $order->id], 201);
    }

    public function cancel(Request $request, CustomOrder $order): JsonResponse
    {
        try {
            $this->orders->cancel($request->user(), $order);
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['status' => 'cancelled'], 200);
    }

    public function approve(Request $request, CustomOrder $order): JsonResponse
    {
        try {
            $this->orders->approve($request->user(), $order);
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['status' => 'released'], 200);
    }

    public function dispute(Request $request, CustomOrder $order): JsonResponse
    {
        try {
            $this->orders->dispute($request->user(), $order);
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['status' => 'disputed'], 200);
    }

    private function fail(CustomOrderException $e): JsonResponse
    {
        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }
}
