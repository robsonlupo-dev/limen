<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleAvailabilityRequest;
use Illuminate\Http\JsonResponse;

/**
 * Visibilidade da performer no catálogo (fix/panel-polish-v1).
 *
 * A presença "online" agora é DERIVADA da sessão (PerformerProfile::isOnline,
 * a partir de `last_active_at`) — a performer fica online sozinha enquanto tem
 * sessão ativa, sem botão. Este endpoint controla apenas o OPT-OUT
 * `appear_offline`: ligado, ela fica invisível no catálogo (nunca aparece como
 * online, faixa de atividade suprimida) mas CONTINUA recebendo mensagens
 * normalmente — receber mensagem nunca dependeu deste estado.
 *
 * Um endpoint só, PATCH, idempotente. O campo é `visible` (desejo de aparecer);
 * "invisível" é `appear_offline = true`.
 */
class AvailabilityController extends Controller
{
    public function toggle(ToggleAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->performerProfile;

        // Conta de performer sem perfil (onboarding nunca concluído): não há o
        // que ajustar. 404 e não 500 — o dashboard não oferece o toggle nesse
        // estado, então quem chega aqui é requisição fora do fluxo.
        abort_unless($profile !== null, 404);

        // Sem `visible` no corpo, inverte o estado atual (visível = ! appear_offline).
        $desiredVisible = $request->desiredVisible() ?? $profile->appear_offline;

        // forceFill: `appear_offline` fica FORA do $fillable de propósito (mesma
        // regra de discrete_mode e do segredo do 2FA) — a escrita só acontece
        // aqui, por atribuição direta, nunca de um payload de massa.
        $profile->forceFill([
            'appear_offline' => ! $desiredVisible,
        ])->save();

        return response()->json([
            // O estado do TOGGLE (visível/invisível), não a presença ao vivo:
            // "online agora" depende de last_active_at e é decidido na leitura do
            // catálogo, não aqui. A tela reflete só a escolha da performer.
            'visible' => ! $profile->appear_offline,
        ]);
    }
}
