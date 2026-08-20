<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PerformerEarningsRequest;
use App\Services\PerformerEarningsService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extrato de GANHOS da performer (fix/chat-ux-mobile). Somente leitura do ledger —
 * ver PerformerEarningsService. Vive na seção "Ganhos" ao lado de Saques/Histórico.
 */
class EarningsController extends Controller
{
    public function __construct(private PerformerEarningsService $earnings) {}

    public function index(PerformerEarningsRequest $request): Response
    {
        Gate::authorize('performer-active');

        $user = $request->user();

        $filters = [
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'type' => $request->validated('type') ?? 'all',
        ];

        return Inertia::render('Performer/Earnings/Index', [
            'entries' => $this->earnings->paginate($user, $filters)->withQueryString(),
            'summary' => $this->earnings->summary($user),
            'typeGroups' => $this->earnings->typeGroups(),
            'filters' => $filters,
        ]);
    }
}
