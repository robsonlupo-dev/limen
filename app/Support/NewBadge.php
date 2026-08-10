<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Selo "Nova/Novo" — sinal de entrada recente no catálogo (feat/activity-badges).
 *
 * Dona única da JANELA. Inspirado no "New member" do mercado: dá ao catálogo a
 * sensação de movimento sem mentir. Usada nas DUAS vitrines:
 *  - performer (PerformerPublicResource::is_new, a partir do `created_at` do
 *    perfil — só performer verificada aparece no catálogo, então "entrou" ≈ está
 *    na vitrine há pouco);
 *  - membro (MemberCatalogService::mask::is_new, a partir do `created_at` do user).
 *
 * BOOLEANO derivado, nunca o timestamp. Mesma disciplina do `is_boosted` /
 * `is_available` / `activity_label`: o card recebe o SINAL, nunca a data que o
 * dataria ao minuto. A janela de 7 dias é grossa o bastante (mais grossa que a
 * faixa "Ativa hoje") para não localizar a criação da conta no tempo; o selo
 * apaga sozinho na leitura ao vencer, sem job.
 */
class NewBadge
{
    /** Janela em dias. Rolante (últimos 7 dias), como as janelas do ActivitySlot. */
    public const WINDOW_DAYS = 7;

    /**
     * A conta é "nova"? `null` (sem carimbo de criação) é sempre falso — nunca
     * inventamos o sinal. Vencida a janela, volta a falso (o selo some na leitura).
     */
    public static function isNew(?Carbon $createdAt): bool
    {
        if ($createdAt === null) {
            return false;
        }

        return $createdAt->greaterThanOrEqualTo(now()->subDays(self::WINDOW_DAYS));
    }
}
