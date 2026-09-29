<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Services\PerformerInsightsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Insights da performer (roadmap social, Onda 3 — § 3.2). Painel SÓ-LEITURA e
 * AGREGADO — toda a regra e o cache vivem no PerformerInsightsService. Aqui só o
 * gate (performer ativa), a janela pedida e o render.
 */
class InsightsController extends Controller
{
    public function __construct(private PerformerInsightsService $insights) {}

    public function page(Request $request): Response
    {
        Gate::authorize('performer-active');

        // Janela 7 ou 30 dias; o service normaliza qualquer valor fora disso.
        $days = (int) $request->integer('window', 30);

        return Inertia::render('Performer/Insights/Index', [
            'insights' => $this->insights->forOwner($request->user()->performerProfile, $days),
            'windows' => PerformerInsightsService::WINDOWS,
        ]);
    }
}
