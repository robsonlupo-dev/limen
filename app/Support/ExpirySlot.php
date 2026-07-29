<?php

namespace App\Support;

use App\Services\ProfileVisitService;
use Illuminate\Support\Carbon;

/**
 * Tempo restante em FAIXA, nunca em relógio (docs/SECURITY_ISSUES.md § 1.2).
 *
 * Existe como classe própria porque a faixa passou a ter DOIS consumidores — o
 * acesso que a performer vê (`MemberPhotoAccess`) e a lista de fotos do próprio
 * membro (`MemberPhoto`) —, e duas cópias divergiriam. É a mesma disciplina de
 * `FollowerVisibilityService::applyFloorEligibility()` (item 6 do CLAUDE.md):
 * critério exposto em duas telas tem de ter uma dona só, senão a divergência
 * entre elas vira o oráculo.
 *
 * O fuso vem de `ProfileVisitService::DISPLAY_TIMEZONE` e não de
 * `config('app.timezone')` (que é UTC): "hoje" é uma palavra lida por gente no
 * Brasil, e derivar dali rotularia 21:00 em São Paulo como o dia seguinte.
 *
 * > **Ressalva conhecida — a faixa reduz a resolução, não elimina o sinal do
 * > TTL.** Uma foto que nasce em "Expira nesta semana" não escolheu 24h, e isso
 * > é visível já no primeiro carregamento. Qualquer indicador de tempo restante
 * > tem essa propriedade; o que a faixa compra é que o INSTANTE do envio não sai
 * > mais junto. Mesma disciplina de linguagem do painel de visitantes: **não
 * > descreva o indicador como "não revela nada sobre o envio"**.
 */
class ExpirySlot
{
    public const EXPIRED = 'Expirada';

    public const TODAY = 'Expira hoje';

    public const SOME_DAYS = 'Expira em alguns dias';

    public const THIS_WEEK = 'Expira nesta semana';

    /**
     * Até quantos dias de calendário ainda contam como "alguns dias". Acima
     * disso a faixa é a mais grossa que existe — o TTL máximo são 7 dias, então
     * não há faixa depois desta.
     */
    private const SOME_DAYS_MAX = 3;

    public static function for(?Carbon $expiresAt): string
    {
        if ($expiresAt === null || now()->greaterThanOrEqualTo($expiresAt)) {
            return self::EXPIRED;
        }

        $timezone = ProfileVisitService::DISPLAY_TIMEZONE;

        // Comparação por DIA de calendário, não por horas restantes: "hoje" é
        // uma afirmação sobre a data, e arredondar horas ("faltam 20h → hoje")
        // devolveria resolução que a faixa existe para tirar.
        $today = now()->setTimezone($timezone)->startOfDay();
        $expiresOn = $expiresAt->copy()->setTimezone($timezone)->startOfDay();

        $days = (int) $today->diffInDays($expiresOn);

        return match (true) {
            $days <= 0 => self::TODAY,
            $days <= self::SOME_DAYS_MAX => self::SOME_DAYS,
            default => self::THIS_WEEK,
        };
    }
}
