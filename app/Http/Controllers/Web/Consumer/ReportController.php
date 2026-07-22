<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Mail\ReportReceivedMail;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class ReportController extends Controller
{
    public function store(StoreReportRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();

        /** @var class-string<Model> $class */
        $class = Report::classForAlias($data['reportable_type']);
        $reportable = $class::find($data['reportable_id']);

        // Inexistente e invisível respondem IGUAL de propósito: separar os dois
        // devolveria o oráculo de enumeração que Report::visibleTo() fecha.
        if (! $reportable || ! Report::visibleTo($reportable, $request->user())) {
            return $this->fail($request, 'target_not_found', 'Conteúdo não encontrado.', 422);
        }

        if (Report::ownerIdOf($reportable) === $request->user()->id) {
            return $this->fail($request, 'self_report', 'Você não pode denunciar a si mesmo.', 422);
        }

        // Anti-spam: o mesmo denunciante não repete o mesmo (alvo, motivo)
        // dentro da janela. O lock fecha a corrida do duplo-submit — sem ele
        // duas requisições simultâneas passam as duas pelo SELECT e gravam
        // duas linhas, que é justamente o que a janela existe para evitar.
        $lockKey = sprintf(
            'report:%d:%s:%d:%s',
            $request->user()->id,
            $data['reportable_type'],
            $data['reportable_id'],
            $data['reason'],
        );

        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return $this->duplicate($request);
        }

        try {
            $alreadyReported = Report::where('reporter_id', $request->user()->id)
                ->where('reportable_type', $reportable->getMorphClass())
                ->where('reportable_id', $reportable->getKey())
                ->where('reason', $data['reason'])
                ->where('created_at', '>=', now()->subHours(Report::DEDUP_WINDOW_HOURS))
                ->exists();

            if ($alreadyReported) {
                return $this->duplicate($request);
            }

            $report = Report::open(
                $request->user(),
                $reportable,
                $data['reason'],
                $data['details'] ?? null,
            );
        } finally {
            $lock->release();
        }

        // Sem Audit::log aqui, de propósito. A própria linha em `reports` já é
        // o registro (quem, o quê, quando) — duplicá-la no audit_logs só
        // acrescentaria o IP EM CLARO do denunciante ao lado da acusação, num
        // log que muito mais gente lê. Denunciante de coerção é exatamente
        // quem não pode pagar esse preço. A ação do admin, essa sim, é
        // auditada (ver ReportAdminController::update).

        if ($adminAddress = config('mail.admin_address')) {
            Mail::to($adminAddress)->queue(new ReportReceivedMail($report));
        }

        $message = 'Denúncia recebida. Nossa equipe vai analisar.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 200)
            : back()->with('success', $message);
    }

    /**
     * Denúncia repetida responde como sucesso, e de propósito: dizer "você já
     * denunciou isso" confirmaria ao denunciante o estado de denúncias
     * anteriores e daria ao spammer o sinal para variar o payload. Do ponto de
     * vista do usuário legítimo, o efeito é o mesmo — o alvo está reportado.
     */
    private function duplicate(StoreReportRequest $request): JsonResponse|RedirectResponse
    {
        $message = 'Denúncia recebida. Nossa equipe vai analisar.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 200)
            : back()->with('success', $message);
    }

    /**
     * Erro consumível pelo front. Rota web (fora de api/*), então a exceção não
     * viraria JSON sozinha — ver CLAUDE.md, "Duas portas de auth".
     */
    private function fail(
        StoreReportRequest $request,
        string $reason,
        string $message,
        int $status,
    ): JsonResponse|RedirectResponse {
        return $request->expectsJson()
            ? response()->json(['reason' => $reason, 'message' => $message], $status)
            : back()->with('error', $message);
    }
}
