<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\PerformerLocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerformerLocationsRequest;
use App\Services\PerformerLocationService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Edição das localizações da performer (Sprint 13) — a porta dedicada que
 * substituiu os campos `state`/`city` da tela de perfil.
 *
 * Uma rota só, PUT: salva a lista inteira. A regra de negócio (teto, primária,
 * duplicata, sincronia do cache) mora no PerformerLocationService; o controller
 * só delega, como o resto da área da performer. O gate é o mesmo do perfil:
 * `auth` + `2fa` + `documents.accepted` (do grupo) + `role:performer` +
 * `performer-active` (perfil verificado e ativo).
 */
class PerformerLocationController extends Controller
{
    public function __construct(private PerformerLocationService $locations) {}

    public function update(UpdatePerformerLocationsRequest $request): RedirectResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        abort_if(! $profile, 404);

        try {
            $this->locations->setLocations($profile, $request->locations());
        } catch (PerformerLocationException $e) {
            // Backstop das invariantes que o Form Request já cobre — se a corrida
            // sob lock derrubar uma delas, vira erro de campo, nunca 500.
            return back()->withErrors(['locations' => $e->getMessage()]);
        }

        // Só a CONTAGEM: `city` é interno e não entra em audit (mesma disciplina
        // dos outros eventos do perfil). O `performer_profile_id` (subject) e o
        // ator já vão nas colunas do audit.
        Audit::log('performer_locations_updated', $profile, [
            'count' => count($request->locations()),
        ], $request);

        return back()->with('success', 'Localizações atualizadas.');
    }
}
