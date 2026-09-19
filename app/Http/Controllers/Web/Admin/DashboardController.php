<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMetricsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Painel admin de receita (Sprint 16). Fino de propósito: toda consulta é do
 * AdminMetricsService (dona única, sem PII de membro). Rota sob role:admin —
 * moderador NÃO alcança receita (refactor de roles do Sprint 13).
 *
 * O toggle 30/90/ano (?period=) escolhe a janela rolante dos KPIs, do gráfico e
 * do card de período; "Hoje" continua sempre visível. Exportar CSV usa a MESMA
 * janela selecionada.
 */
class DashboardController extends Controller
{
    public function index(Request $request, AdminMetricsService $metrics): View
    {
        $periodKey = (string) $request->query('period', '30');
        if (! array_key_exists($periodKey, AdminMetricsService::PERIODS)) {
            $periodKey = '30';
        }
        $days = AdminMetricsService::periodDays($periodKey);

        $platform = $metrics->platform();
        $pendingPayouts = $metrics->pendingPayouts();

        // Total de pendências para o sino do header (payouts + KYC + denúncias).
        $pendingTotal = $pendingPayouts->count() + (int) $platform['pending_kyc'] + (int) $platform['open_reports'];

        return view('admin.dashboard', [
            'revenue' => $metrics->revenue(),
            'counters' => $metrics->counters(),
            'periodKey' => $periodKey,
            'periodDays' => $days,
            'period' => $metrics->periodRevenue($days),
            'series' => $metrics->salesVsSpendSeries($days),
            'platform' => $platform,
            'splits' => $metrics->splits(),
            'ledgerHealth' => $metrics->ledgerHealth(),
            'pendingPayouts' => $pendingPayouts,
            'pendingTotal' => $pendingTotal,
            'strikeReviewPerformers' => $metrics->strikeReviewPerformers(),
        ]);
    }

    /**
     * Exporta os agregados da janela selecionada como CSV (só números — nenhuma
     * PII de membro, mesma garantia do painel). Streaming para não segurar tudo
     * em memória. Separador ';' e BOM UTF-8: o Excel pt-BR abre certo (vírgula
     * decimal + acento) sem passo de importação.
     */
    public function exportCsv(Request $request, AdminMetricsService $metrics): StreamedResponse
    {
        $periodKey = (string) $request->query('period', '30');
        if (! array_key_exists($periodKey, AdminMetricsService::PERIODS)) {
            $periodKey = '30';
        }
        $days = AdminMetricsService::periodDays($periodKey);

        $period = $metrics->periodRevenue($days);
        $series = $metrics->salesVsSpendSeries($days);
        $splits = $metrics->splits();

        $spendLabels = ['chat' => 'Chat', 'gorjeta' => 'Gorjeta', 'presente' => 'Presente',
            'conteudo' => 'Conteúdo', 'live' => 'Live', 'chamada' => 'Chamada'];

        $filename = 'limen-receita-'.$periodKey.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($period, $series, $splits, $spendLabels, $days) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para o Excel

            $put = fn (array $row) => fputcsv($out, $row, ';');

            $put(['Limen — Painel de Receita']);
            $put(['Janela', $days.' dias']);
            $put(['Gerado em', now()->format('d/m/Y H:i')]);
            $put([]);

            $put(['Resumo da janela']);
            $put(['Tokens vendidos', $period['tokens_sold']]);
            $put(['Receita bruta (R$)', number_format($period['gross_revenue_cents'] / 100, 2, ',', '.')]);
            $put(['Receita', $period['is_estimate'] ? 'estimada' : 'real (Asaas/PIX)']);
            $put(['Tokens pagos (payout)', $period['tokens_paid_out']]);
            $put(['Retenção Limen (tokens)', $period['retention']]);
            $put(['Total gasto (tokens)', $period['spent_total']]);
            $put([]);

            $put(['Gasto por vertical (tokens)']);
            foreach ($spendLabels as $key => $label) {
                $put([$label, $period['spent_by_type'][$key] ?? 0]);
            }
            $put([]);

            $put(['Split por vertical']);
            $put(['Vertical', 'Performer (%)', 'Limen (%)', 'Gasto (tokens)']);
            foreach ($splits as $key => $s) {
                $put([
                    $spendLabels[$key] ?? $key,
                    number_format($s['rate'] * 100, 0),
                    number_format((1 - $s['rate']) * 100, 0),
                    $s['spent'],
                ]);
            }
            $put([]);

            $put(['Vendidos × gastos por bloco']);
            $put(['Período', 'Vendidos', 'Gastos']);
            foreach ($series as $row) {
                $put([$row['date'], $row['sold'], $row['spent']]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
