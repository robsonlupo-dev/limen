<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\CallReservationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ScheduleCallRequest;
use App\Models\CallReservation;
use App\Models\PerformerProfile;
use App\Services\CallReservationService;
use App\Services\TokenService;
use App\Support\CallReservationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agendamento de chamada — LADO DO MEMBRO (feat/scheduled-call-v1). Rotas WEB
 * consumidas por fetch → JSON explícito (fora de api/*, a exceção não vira JSON
 * sozinha). Toda a regra vive no CallReservationService; o controller só delega e
 * traduz CallReservationException para HTTP.
 *
 * A AUTORIZAÇÃO DE DONO da reserva é do service (404 uniforme para reserva de
 * terceiro), NÃO do route-binding — `{reservation}` é resolvido por id (armadilha
 * do SubstituteBindings: testar com id EXISTENTE de terceiro).
 */
class CallReservationController extends Controller
{
    public function __construct(
        private CallReservationService $reservations,
        private TokenService $tokens,
    ) {}

    /** Lista as reservas do próprio membro (tela Inertia). */
    public function index(Request $request): Response
    {
        $items = CallReservation::where('member_id', $request->user()->id)
            ->with('performerProfile')
            ->orderByRaw("FIELD(status, 'confirmed','pending') DESC")
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (CallReservation $r) => CallReservationPresenter::forMember($r))
            ->all();

        return Inertia::render('Consumer/ScheduledCalls', [
            'reservations' => $items,
            // Saldo do membro — alimenta o "minutos restantes" da sala ao entrar.
            'walletBalance' => $this->tokens->balance($request->user()),
        ]);
    }

    /** Membro agenda. `{profile}` = performer_profile_id. */
    public function store(ScheduleCallRequest $request, PerformerProfile $profile): JsonResponse
    {
        try {
            $reservation = $this->reservations->reserve($request->user(), $profile, $request->scheduledAt());
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'deposit_tokens' => $reservation->deposit_tokens,
            'scheduled_at' => $reservation->scheduled_at->toIso8601String(),
        ], 201);
    }

    /** Membro cancela (refund grátis antes de T-2h; depois, forfeit à performer). */
    public function cancel(Request $request, CallReservation $reservation): JsonResponse
    {
        try {
            $this->reservations->cancel($request->user(), $reservation);
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Membro entra (o depósito paga o minuto 1) → devolve o call_id + JWT. */
    public function enter(Request $request, CallReservation $reservation): JsonResponse
    {
        try {
            $bundle = $this->reservations->memberEnter($request->user(), $reservation);
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle);
    }

    private function fail(CallReservationException $e): JsonResponse
    {
        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }
}
