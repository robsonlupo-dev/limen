<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\CallReservationException;
use App\Http\Controllers\Controller;
use App\Models\CallReservation;
use App\Services\CallReservationService;
use App\Support\CallReservationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agendamento de chamada — LADO DA PERFORMER (feat/scheduled-call-v1). Fila de
 * reservas a confirmar/recusar + entrada na sala. Rotas WEB → JSON explícito. A
 * regra vive no CallReservationService; o controller delega e traduz.
 *
 * A performer vê o membro só como FanAlias LABEL (M.13.10). A autorização de dono
 * é do service (404 uniforme), não do route-binding.
 */
class CallReservationController extends Controller
{
    public function __construct(private CallReservationService $reservations) {}

    /** Fila de reservas da performer (tela Inertia). */
    public function index(Request $request): Response
    {
        $profile = $request->user()->performerProfile;

        $items = CallReservation::where('performer_profile_id', $profile?->id)
            ->with('performerProfile')
            ->orderByRaw("FIELD(status, 'pending','confirmed') DESC")
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (CallReservation $r) => CallReservationPresenter::forPerformer($r))
            ->all();

        return Inertia::render('Performer/ScheduledCalls', [
            'reservations' => $items,
            // A própria performer vê seus strikes (não é dado de membro).
            'strike_count' => (int) ($profile?->noshow_strike_count ?? 0),
            'strike_threshold' => (int) config('scheduled_call.strike_review_threshold'),
        ]);
    }

    /** Performer confirma. */
    public function confirm(Request $request, CallReservation $reservation): JsonResponse
    {
        try {
            $this->reservations->confirm($request->user(), $reservation);
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Performer recusa (refund ao membro, sem strike). */
    public function decline(Request $request, CallReservation $reservation): JsonResponse
    {
        try {
            $this->reservations->decline($request->user(), $reservation);
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Performer entra primeiro (abre a sala) → devolve o JWT dela. */
    public function enter(Request $request, CallReservation $reservation): JsonResponse
    {
        try {
            $bundle = $this->reservations->performerEnter($request->user(), $reservation);
        } catch (CallReservationException $e) {
            return $this->fail($e);
        }

        return response()->json($bundle + ['reservation_id' => $reservation->id]);
    }

    private function fail(CallReservationException $e): JsonResponse
    {
        return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }
}
