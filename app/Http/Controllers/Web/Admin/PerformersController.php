<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Gestão de performers (back-office admin). Protegida por auth + admin.access
 * (routes/web.php) — moderador NÃO alcança. Complementa o painel de receita: o
 * dashboard é LEITURA agregada, esta tela é a superfície OPERACIONAL sobre cada
 * performer (status, tier, verificação, strikes de no-show, estado ao vivo).
 *
 * ── Escopo de exposição ──────────────────────────────────────────────────────
 * Performer NÃO é membro anônimo: stage_name, status, tier e strikes são dados
 * de administração legítimos. Ainda assim, back-office é a superfície mais
 * exposta a ombro/print (mesma razão do alias no painel de denúncias e da PII
 * de documento escondida no KYC), então NÃO trazemos e-mail, CPF nem documento —
 * quem precisa do documento em si vai ao KYC/painel do provider. Nenhuma PII de
 * MEMBRO toca esta tela: só se lê `role = performer`.
 *
 * As AÇÕES (conceder tier, banir) reusam os endpoints já existentes e auditados
 * — PerformerTierController e UserBanController. Esta classe é só a fila/leitura.
 */
class PerformersController extends Controller
{
    /** Status de conta que a aba de filtro aceita ('all' = todos). */
    private const STATUSES = ['active', 'pending', 'suspended', 'banned'];

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        $verified = $request->query('verified'); // 'yes' | 'no' | null (todos)
        $term = trim((string) $request->query('q', ''));

        $performers = User::query()
            ->where('role', 'performer')
            ->with('performerProfile')
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($verified === 'yes', fn (Builder $q) => $q->whereHas(
                'performerProfile',
                fn (Builder $p) => $p->where('is_verified', true),
            ))
            ->when($verified === 'no', fn (Builder $q) => $q->where(
                fn (Builder $outer) => $outer
                    ->whereDoesntHave('performerProfile')
                    ->orWhereHas('performerProfile', fn (Builder $p) => $p->where('is_verified', false)),
            ))
            ->when($term !== '', fn (Builder $q) => $q->whereHas(
                'performerProfile',
                fn (Builder $p) => $p->where('stage_name', 'like', '%'.$term.'%'),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $rows = $performers->through(fn (User $user) => [
            'id' => $user->id,
            'profile_id' => $user->performerProfile?->id,
            'stage_name' => $user->performerProfile?->stage_name ?? ('Performer #'.$user->id),
            'has_profile' => $user->performerProfile !== null,
            'world' => $user->performerProfile?->category,
            'status' => $user->status,
            'is_verified' => (bool) $user->performerProfile?->is_verified,
            'is_live' => (bool) $user->performerProfile?->is_live,
            'tier' => $user->performerProfile?->tier,
            'strikes' => (int) ($user->performerProfile?->noshow_strike_count ?? 0),
            'created_at' => $user->created_at,
        ]);

        // Contadores por status para os rótulos das abas (uma query agregada, sem
        // paginação — o admin vê o tamanho de cada fila antes de clicar).
        $counts = User::query()
            ->where('role', 'performer')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.performers', [
            'performers' => $rows,
            'status' => $status,
            'verified' => in_array($verified, ['yes', 'no'], true) ? $verified : null,
            'term' => $term,
            'counts' => $counts,
            'totalAll' => (int) $counts->sum(),
            'tiers' => PerformerProfile::TIERS,
        ]);
    }
}
