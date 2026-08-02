<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\UpdateReportRequest;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\PerformerStory;
use App\Models\Report;
use App\Services\MemberPhotoStore;
use App\Services\PerformerStoryStore;
use App\Support\Audit;
use App\Support\ReporterAlias;
use Illuminate\Database\Eloquent\Model;
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
 * O CONTEÚDO denunciado em si (foto, story, mensagem) é servido pelo
 * EvidenceController, por endpoints dedicados sob a mesma porta `moderacao/*`. A
 * TELA de detalhe (`show`) monta o link para esses endpoints e diz de antemão se
 * a prova ainda existe — `evidenceFor()` abaixo resolve isso sem NUNCA embutir os
 * bytes nem o corpo do chat na prop do Inertia: a página recebe uma URL e o hash,
 * não o conteúdo. Prova ausente (GC recolheu, encerramento levou os bytes) vira
 * "expirado + content_hash", que é a prova que sobrevive ao arquivo.
 */
class ModerationController extends Controller
{
    /** Filtros de status aceitos na fila (o + `all`). */
    private const STATUS_FILTERS = ['pending', 'reviewed', 'resolved', 'dismissed', 'all'];

    public function __construct(
        private MemberPhotoStore $photoStore,
        private PerformerStoryStore $storyStore,
    ) {}

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
            'evidence' => $this->evidenceFor($report),
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
     * O que a tela precisa saber para RENDERIZAR a prova, sem receber a prova.
     *
     * Nunca devolve bytes nem o corpo do chat: para conteúdo de imagem devolve a
     * URL do endpoint de serving (a `<img>` do Vue busca dali, e é esse GET que
     * dispara o audit de "quem viu"); para mensagem devolve só o sinal de que há
     * texto a revelar (o corpo vem do endpoint, sob clique). Se a prova não existe
     * mais no disco, `available:false` e o `content_hash` da linha preservada
     * ocupam o lugar — "prova sem conteúdo", que é o que resta depois do GC.
     *
     * O `content_hash` é `$hidden` no model (evidência não sai em serialização),
     * mas é legível aqui no código — e só o VALOR do hash cruza para a tela, para
     * a moderação poder casá-lo contra listas de hash conhecidas.
     *
     * `$open` é a MESMA condição que o `EvidenceController` aplica no serving: a
     * prova só é servível enquanto há denúncia EM ABERTO sobre o alvo (a retenção
     * de bytes vale para a revisão — § 2.4). Sem isso, a tela ofereceria uma
     * `<img>` cujo GET o endpoint recusaria com 404: divergência entre página e
     * serving vira imagem quebrada. O hash da linha preservada continua exibido
     * mesmo com a fila fechada — é a "prova sem conteúdo" que sobra.
     *
     * @return array<string, mixed>
     */
    private function evidenceFor(Report $report): array
    {
        $open = Report::query()
            ->where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->whereIn('status', Report::OPEN_STATUSES)
            ->exists();

        return match ($report->reportable_type) {
            (new MemberPhoto)->getMorphClass() => $this->imageEvidence(
                MemberPhoto::withTrashed()->find($report->reportable_id),
                $open,
                fn (MemberPhoto $p) => $this->photoStore->exists($p->path_encrypted),
                fn () => route('moderacao.evidence.photo', $report->reportable_id),
            ),
            (new PerformerStory)->getMorphClass() => $this->imageEvidence(
                PerformerStory::withTrashed()->find($report->reportable_id),
                $open,
                fn (PerformerStory $s) => $this->storyStore->exists($s->media_path),
                fn () => route('moderacao.evidence.story', $report->reportable_id),
            ),
            (new Message)->getMorphClass() => $this->messageEvidence($report, $open),
            // Perfil de performer é superfície pública — nada de prova retida para
            // servir; o moderador o vê no próprio catálogo.
            default => ['kind' => 'none', 'available' => false, 'content_hash' => null],
        };
    }

    /**
     * Evidência de imagem (foto efêmera / story). `available` só quando há
     * denúncia aberta ($open), a linha existe E os bytes ainda estão no disco;
     * caso contrário devolve o hash da linha preservada, se houver.
     *
     * @param  callable(Model):bool  $bytesExist
     * @param  callable():string  $urlFor
     * @return array<string, mixed>
     */
    private function imageEvidence(?Model $model, bool $open, callable $bytesExist, callable $urlFor): array
    {
        $available = $model !== null && $open && $bytesExist($model);

        return [
            'kind' => 'image',
            'available' => $available,
            'url' => $available ? $urlFor() : null,
            'content_hash' => $model?->content_hash,
        ];
    }

    /**
     * Evidência de mensagem: só o sinal de que há texto e a URL do endpoint que o
     * revela — o corpo nunca vem na prop da página. Servível só com denúncia
     * aberta, como as imagens.
     *
     * @return array<string, mixed>
     */
    private function messageEvidence(Report $report, bool $open): array
    {
        $available = $open && Message::withTrashed()->whereKey($report->reportable_id)->exists();

        return [
            'kind' => 'text',
            'available' => $available,
            'url' => $available ? route('moderacao.evidence.message', $report->reportable_id) : null,
            'content_hash' => null,
        ];
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
