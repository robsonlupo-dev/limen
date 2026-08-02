<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\UpdateReportRequest;
use App\Models\Report;
use App\Support\Audit;
use App\Support\ReporterAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fila de moderação de denúncias (Sprint 13 — refactor de roles).
 *
 * A porta é `/moderacao/*`, protegida por `auth` + `moderator.access`
 * (moderator OU admin). É a fila SEM os poderes de admin: o moderador vê a
 * denúncia — tipo, motivo, o que o denunciante escreveu — e fecha o caso, mas
 * NÃO alcança KYC, payout, ban ou dado financeiro (esses seguem em `/admin/*`
 * sob `role:admin`).
 *
 * ── Privacidade ────────────────────────────────────────────────────────────
 * O denunciante aparece pseudonimizado por ReporterAlias (nunca id/e-mail/CPF),
 * exatamente como no painel de admin: moderar não exige saber quem denunciou, e
 * a tela é a mais exposta a ombro/print. O alias é estável, então "o mesmo
 * denunciante abriu 12 hoje" continua legível — sinal que separa denúncia de
 * retaliação. O reporter_id cru fica na tabela para ordem judicial.
 *
 * O CONTEÚDO denunciado em si (a foto, a mensagem, o story) NÃO é renderizado
 * aqui: o visualizador de prova retida é o próximo item do backlog do Sprint 13,
 * e é o que ESTA feature destrava. Por ora o moderador vê a denúncia (tipo +
 * motivo + texto do denunciante) e decide encaminhar/fechar — nada de bytes de
 * mídia nem corpo de chat cruzando esta tela.
 */
class ModerationController extends Controller
{
    /** Filtros de status aceitos na fila (o + `all`). */
    private const STATUS_FILTERS = ['pending', 'reviewed', 'resolved', 'dismissed', 'all'];

    /**
     * A fila, paginada e filtrável por status e por tipo de alvo.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');
        if (! in_array($status, self::STATUS_FILTERS, true)) {
            $status = 'pending';
        }

        // Tipo de alvo pelo APELIDO público (performer/message/...). Fora do mapa
        // de REPORTABLE_TYPES, ignora o filtro — nunca deixa um valor cru virar
        // um `where` sobre reportable_type arbitrário.
        $type = $request->query('type');
        $typeClass = $type ? Report::classForAlias($type) : null;

        $reports = Report::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($typeClass, fn ($q) => $q->where('reportable_type', $typeClass))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Report $report) => $this->present($report));

        return Inertia::render('Moderacao/Reports/Index', [
            'reports' => $reports,
            'filters' => [
                'status' => $status,
                'type' => $typeClass ? $type : null,
            ],
            // Facetas para os controles de filtro na tela — derivadas das fontes
            // únicas, nunca listas soltas no Vue.
            'statuses' => self::STATUS_FILTERS,
            'types' => array_keys(Report::REPORTABLE_TYPES),
            'pendingCount' => Report::pending()->count(),
        ]);
    }

    /**
     * Detalhe de uma denúncia. Mesmos dados da linha da fila — a diferença é a
     * tela, que traz as ações de fechamento. Sem eager-load do reportable: o
     * conteúdo denunciado não é servido por aqui (ver cabeçalho da classe).
     */
    public function show(Report $report): Response
    {
        return Inertia::render('Moderacao/Reports/Show', [
            'report' => $this->present($report),
        ]);
    }

    /**
     * Fecha a denúncia: revisada / resolvida / descartada, com nota opcional.
     *
     * `status`, `reviewed_by` e `reviewed_at` são autoridade do servidor —
     * gravados por forceFill, nunca por mass assignment (o $fillable de Report é
     * só o formulário de abertura). A nota do moderador entra pela mesma porta.
     * O moderador NÃO ganha aqui poder de banir/suspender: isso é `/admin/*`.
     */
    public function update(UpdateReportRequest $request, Report $report): RedirectResponse
    {
        $validated = $request->validated();

        $report->forceFill([
            'status' => $validated['status'],
            'moderator_notes' => $validated['moderator_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        // Audit da AÇÃO do moderador. Sem o texto da nota nem o do denunciante —
        // o audit registra que a denúncia #N foi fechada com tal status, por
        // quem, e nada que reintroduza conteúdo/PII na tabela que o Hard Delete
        // preserva com o IP em claro (mesma disciplina de report.reviewed).
        Audit::log('moderation.report_reviewed', $report, [
            'status' => $validated['status'],
        ]);

        return redirect()
            ->route('moderacao.reports.show', $report)
            ->with('success', "Denúncia #{$report->id} marcada como {$validated['status']}.");
    }

    /**
     * Projeção segura de uma denúncia para a tela. Denunciante pseudonimizado,
     * alvo por apelido + id interno (não é PII do membro), e nada do corpo do
     * conteúdo denunciado.
     *
     * @return array<string, mixed>
     */
    private function present(Report $report): array
    {
        return [
            'id' => $report->id,
            'reporter' => ReporterAlias::label($report->reporter_id),
            'target_type' => Report::aliasForClass($report->reportable_type) ?? 'desconhecido',
            'target_id' => $report->reportable_id,
            'reason' => $report->reason,
            'details' => $report->details,
            'moderator_notes' => $report->moderator_notes,
            'status' => $report->status,
            'created_at' => $report->created_at,
            'reviewed_at' => $report->reviewed_at,
        ];
    }
}
