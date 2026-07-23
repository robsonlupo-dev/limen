<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserBanController extends Controller
{
    /**
     * Encerramento PERMANENTE de conta por moderação (`status = 'banned'`).
     * Protegido por auth + role:admin (routes/web.php). Distinto de `suspended`
     * (temporário): ambos barram login (AuthService::attemptLogin), mas o
     * vocabulário e a mensagem ao usuário diferem.
     *
     * `status` fica FORA do $fillable de propósito — a troca é ato de autoridade
     * do servidor, via forceFill, nunca payload (mesma regra de tier/role).
     */
    public function ban(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $admin = $request->user();

        // Foot-guns de um ato irreversível: não se bane a si mesmo (trancaria o
        // próprio acesso) nem a outro admin por esta porta de moderação (banir
        // privilégio é decisão de outra alçada, não da fila de abuso).
        abort_if($user->is($admin), 403, 'Não é possível banir a própria conta.');
        abort_if($user->role === 'admin', 403, 'Contas de administrador não podem ser banidas por aqui.');

        return DB::transaction(function () use ($user, $admin, $validated) {
            $user = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Já banido: não regrava nem re-audita (evita ruído de audit e
            // sobrescrever quem/quando baniu de verdade).
            if ($user->isBanned()) {
                return back()->with('info', "A conta #{$user->id} já está banida.");
            }

            $user->forceFill(['status' => 'banned'])->save();

            // Ban permanente tem de matar o acesso VIVO, não só o próximo login:
            // tokens Sanctum continuariam válidos até expirar. As sessões web
            // ficam de fora aqui (driver de sessão) — follow-up registrado.
            $user->tokens()->delete();

            Audit::log('user.banned', $user, [
                'reason' => $validated['reason'],
                'banned_by' => $admin->id,
            ]);

            return back()->with('success', "Conta #{$user->id} banida permanentemente.");
        });
    }
}
