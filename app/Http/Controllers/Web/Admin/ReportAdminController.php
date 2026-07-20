<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Support\Audit;
use App\Support\ReporterAlias;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportAdminController extends Controller
{
    /**
     * Fila de moderação. Protegida por auth + role:admin (ver routes/web.php).
     *
     * O denunciante aparece pseudonimizado: moderar não exige saber quem é, e o
     * painel é a tela mais exposta a ombro/print. O alias é estável, então o
     * admin ainda enxerga "este mesmo denunciante abriu 12 denúncias hoje" —
     * que é a informação de que a moderação precisa. O reporter_id cru continua
     * na tabela para quando houver ordem judicial.
     */
    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');

        if (! in_array($status, ['pending', 'reviewed', 'resolved', 'dismissed', 'all'], true)) {
            $status = 'pending';
        }

        $reports = Report::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Report $report) => [
                'id' => $report->id,
                'reporter' => ReporterAlias::label($report->reporter_id),
                'target_type' => Report::aliasForClass($report->reportable_type) ?? 'desconhecido',
                'target_id' => $report->reportable_id,
                'reason' => $report->reason,
                'details' => $report->details,
                'status' => $report->status,
                'created_at' => $report->created_at,
                'reviewed_at' => $report->reviewed_at,
            ]);

        return view('admin.reports', [
            'reports' => $reports,
            'status' => $status,
            'pendingCount' => Report::pending()->count(),
        ]);
    }

    /** Marca a denúncia como revisada/resolvida/descartada. */
    public function update(Request $request, Report $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'resolved', 'dismissed'])],
        ]);

        // forceFill: `reviewed_by`/`reviewed_at` estão fora do $fillable de
        // propósito — quem revisou é autoridade do servidor, nunca payload.
        $report->forceFill([
            'status' => $validated['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        Audit::log('report.reviewed', $report, [
            'status' => $validated['status'],
        ]);

        return back()->with('success', "Denúncia #{$report->id} marcada como {$validated['status']}.");
    }
}
