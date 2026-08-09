<?php

namespace App\Support;

use App\Models\CallReservation;
use Illuminate\Support\Facades\URL;

/**
 * Apresentação das reservas de chamada (feat/scheduled-call-v1). FONTE ÚNICA da
 * máscara nas duas telas:
 *
 *  - `forMember`: a tela do MEMBRO. Ele agendou — vê os dados públicos da performer
 *    (stage_name/slug/avatar) e o horário EXATO (é a agenda dele; não é correlação).
 *  - `forPerformer`: a fila da PERFORMER. Vê o FanAlias LABEL do membro (M.13.10 —
 *    nunca id/tier/saldo/nome) e o horário exato (agenda operacional dela). O
 *    agendamento é des-anonimização CONSENTIDA (o membro escolheu chamar), como a
 *    chamada 1:1 — por isso o FanAlias, e nada além dele.
 *
 * `deposit_tokens` aparece dos dois lados como o VALOR travado (o membro sabe o que
 * pagou; a performer, o ganho potencial). Nenhum lado recebe `room_name` (invariante
 * #138) nem `call_session_id`.
 */
class CallReservationPresenter
{
    /** @return array<string, mixed> */
    public static function forMember(CallReservation $reservation): array
    {
        $profile = $reservation->performerProfile;

        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'scheduled_at' => optional($reservation->scheduled_at)->toIso8601String(),
            'slot_minutes' => $reservation->slot_minutes,
            'deposit_tokens' => $reservation->deposit_tokens,
            'performer' => [
                'stage_name' => $profile?->stage_name,
                'slug' => $profile?->slug,
                'avatar_url' => self::avatarUrl($reservation),
            ],
            // Botões: cancelar (grátis vs. forfeit) e entrar (janela aberta).
            'can_cancel' => in_array($reservation->status, CallReservation::ACTIVE_STATUSES, true),
            'free_cancel' => $reservation->freeCancelAvailable(),
            'can_enter' => $reservation->isConfirmed() && $reservation->memberJoinWindowOpen(),
            'performer_entered' => $reservation->performer_entered_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function forPerformer(CallReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'scheduled_at' => optional($reservation->scheduled_at)->toIso8601String(),
            'slot_minutes' => $reservation->slot_minutes,
            'deposit_tokens' => $reservation->deposit_tokens,
            // FanAlias label — nunca member_id/tier/nome (M.13.10).
            'member_label' => FanAlias::label($reservation->performer_profile_id, $reservation->member_id),
            'can_confirm' => $reservation->isPending() && ! $reservation->confirmWindowPassed(),
            'can_decline' => $reservation->isPending(),
            'can_enter' => $reservation->isConfirmed() && $reservation->performerEntryWindowOpen(),
        ];
    }

    private static function avatarUrl(CallReservation $reservation): ?string
    {
        $profile = $reservation->performerProfile;
        if ($profile === null || ! $profile->avatar_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $profile->id, 'type' => 'avatar'],
        );
    }
}
