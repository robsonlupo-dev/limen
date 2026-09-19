<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Segurança por ID do membro (back-office admin). Protegida por auth +
 * admin.access — moderador NÃO alcança.
 *
 * ── Anonimato por padrão + "quebrar o vidro" ─────────────────────────────────
 * O membro é anônimo por design (princípio de privacidade + LGPD). Esta tela
 * NÃO lista membros e NÃO mostra PII por padrão: o admin busca UM membro por ID
 * e vê só dados de moderação (status, cadastro, blacklist, nº de denúncias
 * contra ele). Para agir juridicamente é possível REVELAR a identidade
 * (nome/e-mail) numa ação separada, com MOTIVO obrigatório e trilha de auditoria
 * — mostrada uma vez, nunca persistida nesta camada. CPF/documento continuam
 * fora do app (painel do provider/Didit), como no KYC.
 *
 * Pendência jurídica registrada em docs/PENDENCIAS_JURIDICAS.md: o reveal só
 * deve ser LIGADO em produção após validação do jurídico (LGPD).
 *
 * Suspender/reativar é lógica NOVA (antes só havia ban permanente); banir reusa
 * o UserBanController já auditado.
 */
class MembersController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('id', ''));
        $member = null;
        $notFound = false;
        $stats = [];

        if ($query !== '') {
            // Só ID numérico de MEMBRO (role=consumer). Nada de busca por nome/e-mail
            // — isso reabriria a exposição de PII que a tela existe para evitar.
            $member = ctype_digit($query)
                ? User::where('role', 'consumer')->find((int) $query)
                : null;

            if ($member === null) {
                $notFound = true;
            } else {
                $stats = [
                    'status' => $member->status,
                    'created_at' => $member->created_at,
                    'last_login_at' => $member->last_login_at,
                    'blacklist_hit' => (bool) $member->blacklist_hit,
                    'open_reports' => $this->openReportsAgainst($member),
                ];
            }
        }

        return view('admin.members', [
            'query' => $query,
            'member' => $member,
            'notFound' => $notFound,
            'stats' => $stats,
            // Identidade revelada UMA vez (flash da ação de reveal) — nunca persistida.
            'revealed' => session('revealed'),
        ]);
    }

    /**
     * Denúncias ABERTAS cujo alvo é este membro. O membro é dono de dois tipos de
     * reportable: a foto efêmera (MemberPhoto.user_id) e a mensagem que ENVIOU
     * (Message.sender_id). Subquery por id — não carrega PII nem a lista.
     */
    private function openReportsAgainst(User $member): int
    {
        return Report::query()
            ->whereIn('status', Report::OPEN_STATUSES)
            ->where(function ($q) use ($member) {
                $q->where(fn ($x) => $x
                    ->where('reportable_type', (new MemberPhoto)->getMorphClass())
                    ->whereIn('reportable_id', MemberPhoto::where('user_id', $member->id)->select('id')))
                    ->orWhere(fn ($x) => $x
                        ->where('reportable_type', (new Message)->getMorphClass())
                        ->whereIn('reportable_id', Message::where('sender_id', $member->id)->select('id')));
            })
            ->count();
    }

    /** Guarda comum das ações de moderação: alvo tem de ser membro, nunca admin/si. */
    private function assertActionableMember(User $member, User $admin): void
    {
        abort_if($member->is($admin), 403, 'Não é possível moderar a própria conta.');
        abort_if($member->isAdmin(), 403, 'Contas de administrador não são moderadas por aqui.');
        abort_unless($member->role === 'consumer', 403, 'Esta tela modera apenas membros.');
    }

    /**
     * Suspensão TEMPORÁRIA (status='suspended') — bloqueia login (AuthService),
     * reversível por reactivate(). Distinta do ban permanente. `status` fora do
     * $fillable: troca via forceFill, autoridade do servidor. Auditada.
     */
    public function suspend(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $admin = $request->user();
        $this->assertActionableMember($member, $admin);

        return DB::transaction(function () use ($member, $admin, $validated, $request) {
            $member = User::whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if ($member->status === 'banned') {
                return back()->with('info', "Membro #{$member->id} está banido — suspender não se aplica.");
            }
            if ($member->status === 'suspended') {
                return back()->with('info', "Membro #{$member->id} já está suspenso.");
            }

            $member->forceFill(['status' => 'suspended'])->save();
            $member->tokens()->delete(); // derruba o acesso vivo por API

            Audit::log('member.suspended', $member, [
                'reason' => $validated['reason'],
                'suspended_by' => $admin->id,
            ]);

            return redirect()->route('admin.members', ['id' => $member->id])
                ->with('success', "Membro #{$member->id} suspenso.");
        });
    }

    /** Reativa um membro suspenso (status='active'). Não reativa banido. Auditada. */
    public function reactivate(Request $request, User $member): RedirectResponse
    {
        $admin = $request->user();
        $this->assertActionableMember($member, $admin);

        return DB::transaction(function () use ($member, $admin) {
            $member = User::whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if ($member->status === 'banned') {
                return back()->with('info', "Membro #{$member->id} está banido — reativação de ban não é feita por aqui.");
            }
            if ($member->status !== 'suspended') {
                return back()->with('info', "Membro #{$member->id} não está suspenso.");
            }

            $member->forceFill(['status' => 'active'])->save();

            Audit::log('member.reactivated', $member, ['reactivated_by' => $admin->id]);

            return redirect()->route('admin.members', ['id' => $member->id])
                ->with('success', "Membro #{$member->id} reativado.");
        });
    }

    /**
     * "Quebrar o vidro": revela nome/e-mail de UM membro, com MOTIVO obrigatório
     * e auditoria (quem, quando, por quê, qual membro). A identidade é flashada
     * para exibição ÚNICA — nunca persistida nesta camada, nunca em lista. CPF e
     * documento não entram aqui (seguem no painel do provider).
     */
    public function reveal(Request $request, User $member): RedirectResponse
    {
        // Dark launch: o reveal só funciona com a flag ligada (default off até o
        // parecer do jurídico — docs/PENDENCIAS_JURIDICAS.md item "(e)").
        abort_unless(config('features.member_identity_reveal'), 403, 'Revelação de identidade está desativada.');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $admin = $request->user();
        abort_unless($member->role === 'consumer', 403, 'Esta tela revela apenas membros.');

        Audit::log('member.identity_revealed', $member, [
            'reason' => $validated['reason'],
            'revealed_by' => $admin->id,
        ]);

        return redirect()->route('admin.members', ['id' => $member->id])
            ->with('revealed', [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'reason' => $validated['reason'],
            ]);
    }
}
