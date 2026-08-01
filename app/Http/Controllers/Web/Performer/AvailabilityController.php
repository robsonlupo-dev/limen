<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleAvailabilityRequest;
use Illuminate\Http\JsonResponse;

/**
 * "Disponível para conversa" (Sprint 11) — o Caminho 3 do Interesse Expandido:
 * a performer sinaliza que quer ser abordada agora, em vez de buscar membros.
 *
 * Um endpoint só, PATCH, idempotente. O estado é DERIVADO do carimbo
 * `available_for_chat_at` (PerformerProfile::isAvailableForChat), então "ligar"
 * é carimbar `now()` e "desligar" é `null` — não há booleano guardado a manter
 * em sincronia com um job.
 */
class AvailabilityController extends Controller
{
    public function toggle(ToggleAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->performerProfile;

        // Conta de performer sem perfil (onboarding nunca concluído): não há o
        // que sinalizar. 404 e não 500 — o dashboard não oferece o toggle nesse
        // estado, então quem chega aqui é requisição fora do fluxo.
        abort_unless($profile !== null, 404);

        $desired = $request->desiredValue() ?? ! $profile->isAvailableForChat();

        // forceFill: `available_for_chat_at` fica FORA do $fillable de propósito
        // (mesma regra de discrete_mode e do segredo do 2FA) — a escrita só
        // acontece aqui, por atribuição direta, nunca de um payload de massa.
        $profile->forceFill([
            'available_for_chat_at' => $desired ? now() : null,
        ])->save();

        return response()->json([
            'is_available' => $profile->isAvailableForChat(),
            // Faixa, nunca relógio — e null quando desligado. É o dado dela na
            // tela dela, mas a faixa mantém a disciplina de linguagem do resto
            // do produto (ver PerformerProfile::availabilityRemainingLabel).
            'remaining_label' => $profile->availabilityRemainingLabel(),
        ]);
    }
}
