<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\CallException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateCallSettingsRequest;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;

/**
 * Configuração de chamada privada da performer (Sprint 15). Preço/minuto (piso 5,
 * passo 5) e teto de duração. Os campos ficam fora do $fillable; a escrita passa
 * só pelo CallService (forceFill após validação). Rota WEB consumida por fetch →
 * JSON explícito.
 */
class CallSettingsController extends Controller
{
    public function __construct(private CallService $calls) {}

    public function update(UpdateCallSettingsRequest $request): JsonResponse
    {
        try {
            $this->calls->updateSettings(
                $request->user(),
                $request->pricePerMinute(),
                $request->maxDurationMinutes(),
            );
        } catch (CallException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        $profile = $request->user()->performerProfile->fresh();

        return response()->json([
            'ok' => true,
            'call_price_per_minute' => $profile->call_price_per_minute,
            'call_max_duration_minutes' => $profile->call_max_duration_minutes,
        ]);
    }
}
