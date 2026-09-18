<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMetricsService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(AdminMetricsService $metrics): View
    {
        return view('admin.dashboard', [
            'revenue'                => $metrics->revenue(),
            'counters'               => $metrics->counters(),
            'platform'               => $metrics->platform(),
            'splits'                 => $metrics->splits(),
            'ledgerHealth'           => $metrics->ledgerHealth(),
            'pendingPayouts'         => $metrics->pendingPayouts(),
            'strikeReviewPerformers' => $metrics->strikeReviewPerformers(),
        ]);
    }
}
