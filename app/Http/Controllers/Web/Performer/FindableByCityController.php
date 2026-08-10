<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleFindableByCityRequest;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

/**
 * Opt-in "encontrável por cidade" (filtro de cidade consentido, item 4 da fila).
 *
 * Por padrão a cidade da performer é INTERNA — só a UF aparece no catálogo. Este
 * toggle é o consentimento que faz a cidade (já digitada por ela) passar a
 * FILTRAR a busca — ela nunca é EXIBIDA, mas quem filtrar por aquela cidade
 * encontra a performer. Default OFF; ligar é decisão explícita dela.
 *
 * Um endpoint só, PATCH, idempotente — espelha o AvailabilityController.
 */
class FindableByCityController extends Controller
{
    public function toggle(ToggleFindableByCityRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->performerProfile;

        abort_unless($profile !== null, 404);

        $desired = $request->desiredValue() ?? ! $profile->findable_by_city;

        // forceFill: `findable_by_city` fica FORA do $fillable de propósito
        // (disciplina de discrete_mode / available_for_chat_at) — a escrita só
        // acontece aqui, nunca de um payload de massa.
        $profile->forceFill(['findable_by_city' => $desired])->save();

        // Audit da PRÓPRIA performer sobre a própria conta — decisão de
        // findability, sem nenhum dado de membro. Sujeito é o perfil dela.
        Audit::log('performer.findable_by_city_toggled', $profile, [
            'findable_by_city' => $profile->findable_by_city,
        ], $request);

        return response()->json([
            'findable_by_city' => $profile->findable_by_city,
        ]);
    }
}
