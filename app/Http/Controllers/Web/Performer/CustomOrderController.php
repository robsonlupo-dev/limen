<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\CustomOrderException;
use App\Exceptions\VideoProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\DeliverCustomOrderRequest;
use App\Models\CustomOrder;
use App\Services\CustomOrderService;
use App\Support\CustomOrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encomendas sob medida — lado da PERFORMER (Onda 4 §4.3). Fila de pedidos recebidos:
 * aceitar (escrow), recusar, entregar (peça do cofre). O membro aparece SÓ por FanAlias.
 */
class CustomOrderController extends Controller
{
    public function __construct(private CustomOrderService $orders) {}

    public function index(Request $request): Response
    {
        Gate::authorize('performer-active');
        $profile = $request->user()->performerProfile;

        $orders = CustomOrder::where('performer_profile_id', $profile?->id)
            ->with('deliveredContent')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CustomOrder $o) => CustomOrderPresenter::forPerformer($o));

        return Inertia::render('Performer/CustomOrders/Index', ['orders' => $orders]);
    }

    public function accept(Request $request, CustomOrder $order): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $this->orders->accept($request->user(), $order);
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['status' => 'accepted'], 200);
    }

    public function decline(Request $request, CustomOrder $order): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $this->orders->decline($request->user(), $order);
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        }

        return response()->json(['status' => 'declined'], 200);
    }

    public function deliver(DeliverCustomOrderRequest $request, CustomOrder $order): JsonResponse
    {
        Gate::authorize('performer-active');

        try {
            $this->orders->deliver($request->user(), $order, $request->file('arquivo'));
        } catch (CustomOrderException $e) {
            return $this->fail($e);
        } catch (VideoProcessingException $e) {
            return response()->json(['reason' => 'video_invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'delivered'], 200);
    }

    private function fail(CustomOrderException $e): JsonResponse
    {
        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }
}
