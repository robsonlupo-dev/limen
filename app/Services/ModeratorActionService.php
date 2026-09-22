<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use App\Models\Warning;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ações de moderação sobre o alvo de uma denúncia (feat/moderator-actions).
 *
 * Decisão de papel (19/09/2026, PO): o moderador ADVERTE e SUSPENDE (temporário);
 * o BAN PERMANENTE continua exclusivo do admin — o moderador ESCALA. Toda ação é
 * auditada (quem/quando/por quê) e o alvo nunca é admin nem o próprio moderador.
 * `status`/`suspended_until` são autoridade do servidor (forceFill, fora do
 * $fillable). Sem PII: o alvo é resolvido do reportable, nunca digitado.
 */
class ModeratorActionService
{
    /** Janela permitida de suspensão temporária, em dias. */
    public const SUSPEND_MIN_DAYS = 1;
    public const SUSPEND_MAX_DAYS = 90;

    /** Advertência a partir de uma DENÚNCIA: resolve o alvo do reportable. */
    public function warn(Report $report, User $moderator, string $reason): Warning
    {
        return $this->warnUser($this->targetUser($report), $moderator, $reason, $report);
    }

    /**
     * Advertência a um USUÁRIO direto (append-only + trilha). O `$report` é
     * opcional: presente na fila de denúncias, NULL na fila de conteúdo
     * sinalizado (feat/flagged-content-queue) — `warnings.report_id` é nullable.
     * Devolve a Warning criada.
     */
    public function warnUser(User $target, User $moderator, string $reason, ?Report $report = null): Warning
    {
        $this->assertActionable($target, $moderator);

        return DB::transaction(function () use ($target, $moderator, $reason, $report) {
            $warning = new Warning(['reason' => $reason]);
            $warning->user_id = $target->id;
            $warning->issued_by = $moderator->id;
            $warning->report_id = $report?->id;
            $warning->save();

            Audit::log('moderator.warned', $target, [
                'reason' => $reason,
                'issued_by' => $moderator->id,
                'report_id' => $report?->id,
            ]);

            return $warning;
        });
    }

    /**
     * Suspensão TEMPORÁRIA: status=suspended + suspended_until=now+dias. O login
     * reativa sozinho quando o prazo passa (AuthService). Derruba o acesso vivo
     * por API. Ban permanente NÃO acontece aqui (é do admin).
     */
    public function suspend(Report $report, User $moderator, string $reason, int $days): User
    {
        return $this->suspendUser($this->targetUser($report), $moderator, $reason, $days, $report);
    }

    /**
     * Suspensão de um USUÁRIO direto. `$report` opcional (NULL na fila de conteúdo
     * sinalizado). Mesmo corte de acesso vivo e mesma trilha.
     */
    public function suspendUser(User $target, User $moderator, string $reason, int $days, ?Report $report = null): User
    {
        $days = max(self::SUSPEND_MIN_DAYS, min(self::SUSPEND_MAX_DAYS, $days));
        $this->assertActionable($target, $moderator);

        return DB::transaction(function () use ($target, $moderator, $reason, $days, $report) {
            $target = User::whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            abort_if($target->status === 'banned', 422, 'Conta banida — suspensão não se aplica.');

            $until = now()->addDays($days);
            $target->forceFill(['status' => 'suspended', 'suspended_until' => $until])->save();
            $target->tokens()->delete(); // corta o acesso vivo por API

            Audit::log('moderator.suspended', $target, [
                'reason' => $reason,
                'days' => $days,
                'suspended_until' => $until->toIso8601String(),
                'suspended_by' => $moderator->id,
                'report_id' => $report?->id,
            ]);

            return $target;
        });
    }

    /**
     * Escala a denúncia ao admin (o moderador não bane). Marca quem/quando; a
     * denúncia passa a aparecer na fila "Escalados ao admin". Idempotente.
     */
    public function escalate(Report $report, User $moderator, string $reason): Report
    {
        return DB::transaction(function () use ($report, $moderator, $reason) {
            $report = Report::whereKey($report->getKey())->lockForUpdate()->firstOrFail();

            if ($report->escalated_at !== null) {
                return $report; // já escalada — no-op
            }

            $report->forceFill([
                'escalated_at' => now(),
                'escalated_by' => $moderator->id,
            ])->save();

            Audit::log('moderator.escalated', $report, [
                'reason' => $reason,
                'escalated_by' => $moderator->id,
            ]);

            return $report;
        });
    }

    /** Alvo (dono do conteúdo denunciado). Sem PII — resolvido do reportable. */
    private function targetUser(Report $report): User
    {
        $reportable = $report->reportable;
        abort_if($reportable === null, 422, 'O conteúdo denunciado não está mais disponível.');

        $user = User::find(Report::ownerIdOf($reportable));
        abort_if($user === null, 422, 'Alvo da denúncia não encontrado.');

        return $user;
    }

    /** Alvo tem de ser membro ou performer — nunca admin nem o próprio moderador. */
    private function assertActionable(User $target, User $moderator): void
    {
        abort_if($target->is($moderator), 403, 'Não é possível moderar a própria conta.');
        abort_if($target->isAdmin(), 403, 'Contas de administrador não são moderadas por aqui.');
        abort_unless(in_array($target->role, ['consumer', 'performer'], true), 403, 'Alvo inválido para esta ação.');
    }
}
