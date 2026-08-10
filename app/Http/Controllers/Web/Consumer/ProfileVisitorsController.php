<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Services\ProfileVisitService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Quem visitou seu perfil" — as PERFORMERS que visitaram este membro (visitas
 * bidirecionais, A.0.4).
 *
 * É o inverso do painel de visitantes da performer: lá o membro aparece sob
 * FanAlias, com piso e k-anonimato; aqui é a PERFORMER que se expõe, com a
 * identidade pública do catálogo (nome artístico, avatar, slug) — sem piso, sem
 * k, sem paywall. Seguro por natureza: performer é pública, e nenhum membro é
 * exposto a ninguém nesta tela. Nada de PII, nada de tier.
 *
 * v1 SEM gate: a lista completa para todo membro. Monetizar (gate por tier) é
 * decisão futura de PO — outro PR. A regra e a máscara vivem no
 * ProfileVisitService; o controller só delega.
 */
class ProfileVisitorsController extends Controller
{
    public function __construct(private ProfileVisitService $visits) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Consumer/Visitors/Index', [
            'performers' => $this->visits->memberVisitorsPanelFor($request->user())->all(),
        ]);
    }
}
