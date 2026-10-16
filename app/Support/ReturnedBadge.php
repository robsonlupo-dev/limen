<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Selo "Voltou" — performer que estava inativa há muito tempo e reapareceu
 * (roadmap Fatal Model, Onda A). Espelha o [[NewBadge]] e segue a MESMA
 * disciplina: BOOLEANO derivado, nunca o timestamp.
 *
 * A JANELA de exibição mora aqui (dona única). O carimbo `users.returned_at` é
 * escrito pelo TrackPerformerActivity quando a performer volta a ficar ativa
 * depois de mais de RETURN_GAP_DAYS inativa (lá) — aqui só se decide por quantos
 * dias o selo fica aceso depois disso. Vencida a janela, apaga sozinho na
 * leitura, sem job, como o "Nova".
 *
 * Privacidade: é sinal de PRESENÇA (a performer reapareceu), então é suprimido
 * sob `appear_offline`/Ghost/Status Invisível na MESMA porta que o
 * `activity_label` — e, na origem, o middleware nem carimba `returned_at` para
 * quem está em Ghost/Invisível. O selo (grosso, 7 dias) não data a volta ao
 * minuto, igual ao "Nova".
 */
class ReturnedBadge
{
    /** Por quantos dias o selo "Voltou" fica aceso após o retorno. Rolante. */
    public const WINDOW_DAYS = 7;

    /**
     * A performer "voltou" recentemente? `null` (nunca carimbou retorno) é sempre
     * falso — nunca inventamos o sinal. Vencida a janela, volta a falso.
     */
    public static function isReturned(?Carbon $returnedAt): bool
    {
        if ($returnedAt === null) {
            return false;
        }

        return $returnedAt->greaterThanOrEqualTo(now()->subDays(self::WINDOW_DAYS));
    }
}
