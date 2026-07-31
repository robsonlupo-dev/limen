<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carimba `last_active_at` da performer a cada request autenticado dela.
 *
 * Alimenta a faixa "Última atividade" (App\Support\ActivitySlot). O carimbo em
 * si nunca sai da aplicação — é `$hidden` e nenhum resource o expõe; o público
 * só vê a faixa derivada.
 *
 * Três decisões que não são opcionais:
 *
 * 1. **Throttle de 5 min, LIDO da própria coluna.** Gravar a cada request seria
 *    um write por página. O throttle é decidido comparando o `last_active_at`
 *    atual com agora: dentro da janela, o request não escreve. Sem cache
 *    externo, o teste "2 requests em 1s = 1 update" cai direto do estado do
 *    banco.
 *
 * 2. **Ghost Mode e Status Invisível SUPRIMEM o carimbo.** A supressão vive na
 *    ESCRITA, não na leitura: se dependesse de a faixa esconder o valor, o
 *    timestamp real ficaria no banco esperando um bug de serialização. Não
 *    gravar é a única garantia de que o dado não existe. Checa os atributos
 *    CRUS (`ghost_mode` / `invisible_status`), não `hasGhostMode()`: aquele é o
 *    perk EFETIVO do consumidor, resolvido por tier, e devolveria sempre `false`
 *    para uma performer — o que tornaria esta supressão letra morta.
 *
 * 3. **Só performer.** O carimbo não tem consumidor para membro nem admin —
 *    "última atividade" é superfície da vitrine, e só a performer é vitrine.
 *
 * Fica no append do grupo `web`, DEPOIS do BlockBannedUsers: uma performer
 * banida é derrubada antes de chegar aqui, então não é marcada como ativa.
 */
class TrackPerformerActivity
{
    /** Janela mínima entre dois carimbos. Não é relógio exposto — só espaça o write. */
    public const THROTTLE_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $this->touch($request->user());

        return $next($request);
    }

    private function touch(?User $user): void
    {
        if (! $user || $user->role !== 'performer') {
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

        // Grava só a coluna, sem bumpar `updated_at` nem disparar observers: é um
        // heartbeat, não uma mutação de domínio. `saveQuietly` evita eventos de
        // model; `timestamps = false` mantém o `updated_at` livre desse ruído.
        $user->last_active_at = now();
        $user->timestamps = false;
        $user->saveQuietly();
        $user->timestamps = true;
    }
}
