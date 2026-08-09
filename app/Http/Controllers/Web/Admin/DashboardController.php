<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMetricsService;
use Illuminate\Contracts\View\View;

/**
 * Painel admin de receita (Sprint 16). Fino de propósito: toda consulta é do
 * AdminMetricsService (dona única, sem PII de membro). Rota sob role:admin —
 * moderador NÃO alcança receita (refactor de roles do Sprint 13).
 */
class DashboardController extends Controller
{
    public function index(AdminMetricsService $metrics): View
    {
        return view('admin.dashboard', [
            'revenue' => $metrics->revenue(),
            'counters' => $metrics->counters(),
            'pendingPayouts' => $metrics->pendingPayouts(),
            'strikeReviewPerformers' => $metrics->strikeReviewPerformers(),
        ]);
    }
}
