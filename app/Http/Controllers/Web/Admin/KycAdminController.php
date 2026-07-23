<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\KycService;
use App\Services\SharedRegistrationIpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KycAdminController extends Controller
{
    /** Estados que ainda aceitam decisão humana. O resto é histórico. */
    private const ACTIONABLE = ['pending', 'review'];

    public function __construct(
        private KycService $kyc,
        private SharedRegistrationIpService $sharedIps,
    ) {}

    /**
     * Fila de aprovação de KYC. Protegida por auth + role:admin (routes/web.php).
     *
     * A PII do documento (document_number, full_legal_name, date_of_birth e os
     * paths dos arquivos) NUNCA chega à view: o admin decide pelo status do
     * provider e pelos sinais — quem precisa conferir o documento em si faz
     * isso no painel do provider. Tela de back-office é a mais exposta a
     * ombro/print (mesma razão do alias no painel de denúncias).
     */
    public function index(Request $request): View
    {
        $status = $request->query('status', 'queue');

        // 'queue' (default) é a fila de trabalho: pending + review juntos.
        if (! in_array($status, ['queue', 'pending', 'review', 'approved', 'rejected'], true)) {
            $status = 'queue';
        }

        $page = IdentityVerification::query()
            ->with(['user.performerProfile', 'reviewer'])
            ->when(
                $status === 'queue',
                fn ($q) => $q->whereIn('status', self::ACTIONABLE),
                fn ($q) => $q->where('status', $status),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Sinal de possível rede de exploração, mesma mecânica da fila da API
        // (AdminKycController::index): resolvido em UMA query para a página
        // toda, antes da serialização. Sinaliza, nunca bloqueia.
        $sharedIpCounts = $this->sharedIps->othersCountFor(
            collect($page->items())->pluck('user')->filter()
        );

        $verifications = $page->through(fn (IdentityVerification $v) => [
            'id' => $v->id,
            'status' => $v->status,
            'provider_status' => $v->provider_status,
            'created_at' => $v->created_at,
            'reviewed_at' => $v->reviewed_at,
            'user' => [
                'id' => $v->user?->id,
                'name' => $v->user?->name,
                'email' => $v->user?->email,
                'stage_name' => $v->user?->performerProfile?->stage_name,
            ],
            'reviewer' => $v->reviewer ? ['name' => $v->reviewer->name] : null,
            'shared_ip_others' => $sharedIpCounts[$v->user?->id] ?? 0,
            'blacklist_hit' => (bool) $v->user?->blacklist_hit,
            'actionable' => in_array($v->status, self::ACTIONABLE, true),
        ]);

        return view('admin.kyc', [
            'verifications' => $verifications,
            'status' => $status,
            'queueCount' => IdentityVerification::whereIn('status', self::ACTIONABLE)->count(),
        ]);
    }

    /**
     * Aprova a verificação. A mutação inteira (status, is_verified do perfil,
     * ativação do user, age_confirmed, audit, e-mail) vive em KycService — a
     * MESMA fonte do webhook Didit e da API admin. Duplicar aqui criaria uma
     * segunda cópia dos invariantes de aprovação.
     *
     * lockForUpdate + re-check do status: dois admins clicando "Aprovar" ao
     * mesmo tempo (ou um duplo clique) não aprovam duas vezes nem disparam o
     * e-mail em dobro — mesmo padrão do lock do KYC submit.
     */
    public function approve(Request $request, IdentityVerification $verification): RedirectResponse
    {
        return DB::transaction(function () use ($request, $verification) {
            $verification = IdentityVerification::whereKey($verification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($verification->status, self::ACTIONABLE, true)) {
                return back()->with(
                    'info',
                    "Verificação #{$verification->id} já está \"{$verification->status}\" — nada foi regravado.",
                );
            }

            $this->kyc->approve($verification, $request->user()->id);

            return back()->with('success', "Verificação #{$verification->id} aprovada.");
        });
    }

    /** Rejeita com motivo obrigatório — o motivo vai no e-mail e no audit. */
    public function reject(Request $request, IdentityVerification $verification): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $verification, $validated) {
            $verification = IdentityVerification::whereKey($verification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($verification->status, self::ACTIONABLE, true)) {
                return back()->with(
                    'info',
                    "Verificação #{$verification->id} já está \"{$verification->status}\" — nada foi regravado.",
                );
            }

            $this->kyc->reject($verification, $validated['reason'], $request->user()->id);

            return back()->with('success', "Verificação #{$verification->id} rejeitada.");
        });
    }
}
