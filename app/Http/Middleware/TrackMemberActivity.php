<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carimba `last_active_at` do MEMBRO a cada request autenticado dele — o par do
 * TrackPerformerActivity, para o indicador "Online agora" que a performer vê no
 * perfil e no card (feat/member-online-presence, etapa 2b).
 *
 * Mesmas três decisões do middleware da performer, e pelos mesmos motivos:
 *
 * 1. **Throttle, LIDO da própria coluna** (sem cache externo). Aqui a janela é
 *    de 2 min, MENOR que a de exibição do "online" (User::ONLINE_WINDOW_MINUTES
 *    = 5): quem está navegando re-carimba a cada ≤2 min, então o online de 5 min
 *    cobre com folga e não pisca entre um carimbo e o próximo.
 *
 * 2. **Status Invisível e Ghost Mode SUPRIMEM o carimbo, na ESCRITA.** Não
 *    gravar é a única garantia de que o instante não existe no banco esperando um
 *    bug de serialização — a mesma disciplina da faixa de atividade. Atributos
 *    CRUS (`invisible_status`/`ghost_mode`), não o perk efetivo por tier.
 *
 * 3. **Só membro** (`consumer`). Performer tem o seu; admin/guest não têm
 *    consumidor para presença.
 *
 * O carimbo NUNCA sai como timestamp (é `$hidden`); a performer só vê o BOOLEANO
 * derivado `User::isOnlineNow()`. Fica no append do grupo `web`, depois do
 * BlockBannedUsers/BlockSuspendedUsers (membro barrado não conta como ativo).
 */
class TrackMemberActivity
{
    /** Janela mínima entre dois carimbos. Menor que a janela de "online" (ver 1). */
    public const THROTTLE_MINUTES = 2;

    public function handle(Request $request, Closure $next): Response
    {
        $this->touch($request->user());

        return $next($request);
    }

    private function touch(?User $user): void
    {
        if (! $user || $user->role !== 'consumer') {
            return;
        }

        // Atributos crus, não o perk efetivo (ver cabeçalho, decisão 2).
        if ($user->ghost_mode || $user->invisible_status) {
            return;
        }

        $last = $user->last_active_at;

        // Dentro da janela de throttle: não escreve (decisão 1).
        if ($last !== null && $last->greaterThan(now()->subMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        // Heartbeat: grava só a coluna, sem bumpar updated_at nem disparar
        // observers (mesma disciplina do TrackPerformerActivity).
        $user->last_active_at = now();
        $user->timestamps = false;
        $user->saveQuietly();
        $user->timestamps = true;
    }
}
