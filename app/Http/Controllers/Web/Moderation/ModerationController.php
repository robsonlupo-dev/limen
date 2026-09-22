<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\UpdateReportRequest;
use App\Models\AuditLog;
use App\Models\ContentFlag;
use App\Models\MemberGalleryPhoto;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\PerformerStory;
use App\Models\PerformerVoiceIntro;
use App\Models\Report;
use App\Models\User;
use App\Services\ContentFlagService;
use App\Services\MemberNicknameService;
use App\Services\MemberPhotoStore;
use App\Services\ModeratorActionService;
use App\Services\PerformerStoryStore;
use App\Services\ProfileVisitService;
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

    /**
     * Ações do moderador que a fila "Minhas ações" lista (feat/moderation-queues-hub).
     * São as ações que passam pela área de moderação — o audit já grava cada uma
     * com o user_id de quem agiu.
     */
    private const MODERATOR_ACTIONS = [
        'moderation.report_reviewed',
        'moderator.warned',
        'moderator.suspended',
        'moderator.escalated',
        'member_nickname_removed_by_moderator',
        // Dispensa de sinalizações automáticas (feat/flagged-content-queue).
        'moderation.flags_dismissed',
    ];

    public function __construct(
        private MemberPhotoStore $photoStore,
        private PerformerStoryStore $storyStore,
    ) {}

    /**
     * A fila, paginada e filtrável por status e por tipo de alvo.
     */
    /**
     * Página inicial da moderação: um resumo das TRÊS filas (pendentes em cada) e
     * atalhos. O moderador cai aqui em vez de direto numa fila. Só contagens —
     * nenhum conteúdo denunciado nem PII (o serving de prova segue nas telas).
     */
    public function overview(Request $request): Response
    {
        return Inertia::render('Moderacao/Overview', [
            'queues' => [
                'reports' => Report::pending()->count(),
                // Denúncias abertas escaladas ao admin e as fora do SLA (Fase 3/4).
                'escalated' => Report::escalated()->count(),
                'overdue' => Report::overdue()->count(),
                'member_photos' => MemberGalleryPhoto::pending()->count(),
                'voice_intros' => PerformerVoiceIntro::pending()->count(),
                // Usuários com conteúdo auto-sinalizado pendente (feat/flagged-
                // content-queue). Conta USUÁRIOS distintos, não flags — a fila
                // agrega por usuário (reincidência).
                'flagged' => $this->flaggedUserCount(),
                // Ações do próprio moderador hoje (fuso de exibição).
                'my_actions_today' => AuditLog::where('user_id', $request->user()->id)
                    ->whereIn('action', self::MODERATOR_ACTIONS)
                    ->where('created_at', '>=', now(ProfileVisitService::DISPLAY_TIMEZONE)->startOfDay())
                    ->count(),
            ],
        ]);
    }

    /**
     * "Minhas ações" (feat/moderation-queues-hub): o histórico das ações DO
     * moderador logado, lido do audit. Só as ações dele (filtro por user_id) e
     * sem PII do denunciante — mostra o tipo de alvo, o id e o motivo que ELE
     * mesmo escreveu. Read-only.
     */
    public function myActions(Request $request): Response
    {
        $actions = AuditLog::where('user_id', $request->user()->id)
            ->whereIn('action', self::MODERATOR_ACTIONS)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject_type' => Report::aliasForClass($log->subject_type)
                    ?? class_basename((string) $log->subject_type),
                'subject_id' => $log->subject_id,
                'reason' => $log->metadata['reason'] ?? null,
                'days' => $log->metadata['days'] ?? null,
                'created_at' => $log->created_at,
            ])
            ->all();

        return Inertia::render('Moderacao/MyActions', [
            'actions' => $actions,
        ]);
    }

    /**
     * Fila de CONTEÚDO SINALIZADO (feat/flagged-content-queue, Fase 4c). Agrega os
     * flags pendentes POR USUÁRIO — a moderação age por reincidência, então o
     * cartão é o usuário, ordenado por nº de flags (mais reincidente no topo) e,
     * em empate, pela sinalização mais recente. Sem PII e sem corpo: só id interno,
     * papel, contagem, fontes e a data do último flag.
     */
    public function flaggedContent(Request $request): Response
    {
        $flagged = ContentFlag::query()
            ->pending()
            ->selectRaw('user_id, COUNT(*) as flag_count, MAX(created_at) as last_at, GROUP_CONCAT(DISTINCT source) as sources')
            ->groupBy('user_id')
            ->orderByDesc('flag_count')
            ->orderByDesc('last_at')
            ->paginate(50);

        // Papel dos usuários da página (consumer/performer) — o id interno não é
        // PII (é o mesmo que a fila de denúncias já mostra); nome/e-mail nunca sai.
        $roles = User::whereIn('id', collect($flagged->items())->pluck('user_id'))
            ->pluck('role', 'id');

        $flagged->getCollection()->transform(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'role' => $roles[$row->user_id] ?? 'desconhecido',
            'flag_count' => (int) $row->flag_count,
            'last_at' => $row->last_at,
            'sources' => array_values(array_filter(explode(',', (string) $row->sources))),
        ]);

        return Inertia::render('Moderacao/FlaggedContent', [
            'flagged' => $flagged,
            'flaggedUserCount' => $this->flaggedUserCount(),
        ]);
    }

    /** Usuários distintos com flag pendente — a contagem do hub e do cabeçalho. */
    private function flaggedUserCount(): int
    {
        return (int) ContentFlag::pending()->distinct()->count('user_id');
    }

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

        // Fila "Escalados ao admin" (feat/moderation-queues-hub): recorte ortogonal
        // ao status — abertas com escalated_at. Quando ligado, manda no status.
        $escalated = $request->boolean('escalated');

        $reports = Report::query()
            ->when($escalated, fn ($q) => $q->escalated())
            ->when(! $escalated && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($typeClass, fn ($q) => $q->where('reportable_type', $typeClass))
            // Fila de trabalho (pendentes) e escalados saem na ordem de atendimento
            // — prioridade + antiguidade; as demais visões seguem por data desc.
            ->when(
                $escalated || $status === 'pending',
                fn ($q) => $q->orderByPriority(),
                fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Report $report) => $this->present($report));

        return Inertia::render('Moderacao/Reports/Index', [
            'reports' => $reports,
            'filters' => [
                'status' => $status,
                'type' => $typeClass ? $type : null,
                'escalated' => $escalated,
            ],
            // Facetas para os controles de filtro na tela — derivadas das fontes
            // únicas, nunca listas soltas no Vue.
            'statuses' => self::STATUS_FILTERS,
            'types' => array_keys(Report::REPORTABLE_TYPES),
            'pendingCount' => Report::pending()->count(),
            'escalatedCount' => Report::escalated()->count(),
        ]);
    }

    /**
     * Remoção FORÇADA de apelido pelo moderador (feat/member-nickname). O membro
     * volta ao FanAlias até escolher outro. O alvo é o APELIDO — string PÚBLICA e
     * ÚNICA que o moderador já vê em qualquer superfície —, então identificar por
     * ele não expõe nada (nem o user_id, que o FanAlias esconde). Genérico quando
     * não encontra (o apelido pode ter sido trocado/removido no intervalo). O
     * cooldown de troca do membro é PRESERVADO (o serviço não zera o relógio) —
     * senão bastaria se autodenunciar para driblar o limite de 7 dias.
     * (A DENÚNCIA pública do apelido virá em PR próprio — ver handoff.)
     */
    public function removeNickname(Request $request, MemberNicknameService $nicknames): RedirectResponse
    {
        $validated = $request->validate(['nickname' => ['required', 'string', 'max:20']]);

        $member = User::where('nickname_normalized', MemberNicknameService::normalizeUnique($validated['nickname']))->first();

        if ($member === null) {
            return back()->with('error', 'Nenhum membro com esse apelido agora.');
        }

        $nicknames->remove($member, byModerator: true, request: $request);

        return back()->with('success', 'Apelido removido. O membro volta ao identificador até escolher outro.');
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
            // Contexto da tela de trabalho (Fase 2): o que vem a seguir na fila e
            // um retrato rápido do estado dela no rodapé. Só contagens e metadados —
            // nenhum conteúdo denunciado nem PII (o serving de prova é à parte).
            'queue' => $this->queueAfter($report),
            'stats' => $this->reportStats(),
        ]);
    }

    /**
     * "Próximos na fila": as PENDENTES exceto a atual, na ORDEM DE ATENDIMENTO —
     * prioridade (urgente primeiro) e, dentro dela, a mais antiga (feat/moderation-
     * sla-priority). Compacto: só o que o cartão precisa, sem PII do denunciante.
     */
    private function queueAfter(Report $report): array
    {
        return Report::pending()
            ->whereKeyNot($report->id)
            ->orderByPriority()
            ->limit(5)
            ->get()
            ->map(fn (Report $r) => [
                'id' => $r->id,
                'target_type' => Report::aliasForClass($r->reportable_type) ?? 'desconhecido',
                'reason' => $r->reason,
                'priority' => $r->priority,
                'overdue' => $r->isOverdue(),
                'created_at' => $r->created_at,
            ])
            ->all();
    }

    /**
     * Retrato do estado da fila para o rodapé de stats (Fase 2). Números baratos —
     * os indicadores mais pesados (tempo médio, taxa de reversão) ficam para a
     * Fase 5. "resolvidas hoje" usa o fuso de exibição do produto.
     */
    private function reportStats(): array
    {
        $today = now(ProfileVisitService::DISPLAY_TIMEZONE)->toDateString();

        return [
            'pending' => Report::pending()->count(),
            'resolved_today' => Report::where('status', 'resolved')
                ->whereDate('reviewed_at', $today)
                ->count(),
            'escalated' => Report::escalated()->count(),
            // Abertas já fora do SLA (feat/moderation-sla-priority).
            'overdue' => Report::overdue()->count(),
            'oldest_pending_at' => Report::pending()->min('created_at'),
        ];
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
     * Advertir o alvo da denúncia (feat/moderator-actions). Ação leve do
     * moderador — registra advertência (append-only) + trilha. Motivo obrigatório.
     */
    public function warn(Request $request, Report $report, ModeratorActionService $actions): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actions->warn($report, $request->user(), $validated['reason']);

        return back()->with('success', "Advertência registrada na denúncia #{$report->id}.");
    }

    /**
     * Suspender temporariamente o alvo (feat/moderator-actions). Poder novo do
     * moderador (decisão do PO, 19/09): suspensão com prazo, reversível, que
     * expira sozinha. Ban permanente NÃO — isso é escalar ao admin. Motivo e
     * dias obrigatórios.
     */
    public function suspend(Request $request, Report $report, ModeratorActionService $actions): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['required', 'integer', 'min:'.ModeratorActionService::SUSPEND_MIN_DAYS, 'max:'.ModeratorActionService::SUSPEND_MAX_DAYS],
        ]);

        $actions->suspend($report, $request->user(), $validated['reason'], (int) $validated['days']);

        return back()->with('success', "Alvo da denúncia #{$report->id} suspenso por {$validated['days']} dia(s).");
    }

    /**
     * Escalar a denúncia ao admin (feat/moderator-actions). O caminho do ban: o
     * moderador não bane; escala com a recomendação e o admin executa. Motivo
     * obrigatório.
     */
    public function escalate(Request $request, Report $report, ModeratorActionService $actions): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actions->escalate($report, $request->user(), $validated['reason']);

        return back()->with('success', "Denúncia #{$report->id} escalada ao admin.");
    }

    /**
     * Advertir um usuário a partir da fila de conteúdo sinalizado (feat/flagged-
     * content-queue). Sem denúncia de origem — o alvo é o usuário reincidente. A
     * ModeratorActionService guarda os invariantes (não age sobre admin nem sobre
     * a própria conta). Não dispensa os flags: advertir e limpar a fila são atos
     * distintos.
     */
    public function warnFlagged(Request $request, User $user, ModeratorActionService $actions): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actions->warnUser($user, $request->user(), $validated['reason']);

        return back()->with('success', "Advertência registrada para o usuário #{$user->id}.");
    }

    /**
     * Suspender temporariamente um usuário sinalizado (feat/flagged-content-queue).
     * Mesmo poder da fila de denúncias, sem denúncia de origem.
     */
    public function suspendFlagged(Request $request, User $user, ModeratorActionService $actions): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['required', 'integer', 'min:'.ModeratorActionService::SUSPEND_MIN_DAYS, 'max:'.ModeratorActionService::SUSPEND_MAX_DAYS],
        ]);

        $actions->suspendUser($user, $request->user(), $validated['reason'], (int) $validated['days']);

        return back()->with('success', "Usuário #{$user->id} suspenso por {$validated['days']} dia(s).");
    }

    /**
     * Dispensar TODOS os flags pendentes de um usuário (feat/flagged-content-
     * queue): o moderador olhou e decidiu não agir (ou já agiu à parte). O usuário
     * some da fila; um novo flag futuro o traz de volta. Auditado.
     */
    public function dismissFlagged(Request $request, User $user, ContentFlagService $flags): RedirectResponse
    {
        $flags->dismissAllFor($user, $request->user());

        Audit::log('moderation.flags_dismissed', $user, []);

        return back()->with('success', "Sinalizações do usuário #{$user->id} dispensadas.");
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
            // Escalada ao admin (feat/moderator-actions): a tela mostra o selo e
            // desabilita o botão "Escalar" quando já foi.
            'escalated_at' => $report->escalated_at,
            // Prioridade + SLA (feat/moderation-sla-priority): selo de severidade
            // e "vence em / atrasada". `overdue` só é true enquanto aberta.
            'priority' => $report->priority,
            'sla_due_at' => $report->slaDueAt(),
            'overdue' => $report->isOverdue(),
        ];
    }
}
