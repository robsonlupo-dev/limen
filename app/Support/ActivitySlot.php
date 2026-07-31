<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "Última atividade" da performer em FAIXA, nunca em relógio.
 *
 * Mesma disciplina do painel de visitantes (CLAUDE.md, item 12) e da ExpirySlot:
 * o produto mostra que a performer esteve ativa RECENTEMENTE sem devolver o
 * instante. Um "visto por último às 14:31" dataria a atividade ao minuto — e
 * como a performer depende de não ser correlacionada, o horário exato de
 * qualquer ação é dado que não sai daqui. A resolução mais fina é "hoje".
 *
 * Dona única da regra: a coluna `last_active_at` é `$hidden` no User e nenhum
 * resource a expõe como timestamp; a ÚNICA porta para o público é esta classe,
 * consumida pelo PerformerPublicResource (activity_label).
 *
 * As janelas são ROLANTES (últimas 24h / 7d / 30d), não de calendário: é o
 * vocabulário que o mercado usa ("ativa nas últimas 24h") e a granularidade já é
 * grossa o bastante para não localizar um evento no tempo.
 *
 * "Ativa agora" NÃO mora aqui, de propósito. Esse é o estado `is_live`, que é
 * tempo real e já tem a sua própria superfície (a bolinha verde no card, o
 * LiveBadge no perfil). Trazê-lo para cá produziria um rótulo que a exibição
 * sempre suprime quando a performer está ao vivo — a bolinha e o texto são
 * redundantes, e o PO pediu só a bolinha nesse caso. A constante existe como
 * vocabulário; `for()` nunca a devolve.
 */
class ActivitySlot
{
    /** Estado `is_live` — pertence ao indicador de tempo real, não a `for()`. */
    public const NOW = 'Ativa agora';

    public const TODAY = 'Ativa hoje';

    public const THIS_WEEK = 'Ativa esta semana';

    public const THIS_MONTH = 'Ativa este mês';

    /**
     * Faixa de atividade a partir do carimbo, ou null quando não há o que
     * mostrar — nunca registrou atividade (`null`), ou a atividade é mais antiga
     * que a faixa mais grossa (30 dias). null é "não exibir nada": sem
     * placeholder e sem "inativa", que anunciariam a ausência.
     *
     * O chamador passa `null` também quando um perk de privacidade suprimiu o
     * carimbo — a supressão vive na ESCRITA (TrackPerformerActivity nunca grava
     * para quem tem Ghost Mode / Status Invisível), então aqui `null` já chega
     * com essa decisão embutida.
     */
    public static function for(?Carbon $lastActiveAt): ?string
    {
        if ($lastActiveAt === null) {
            return null;
        }

        $now = now();

        return match (true) {
            $lastActiveAt->greaterThanOrEqualTo($now->copy()->subDay()) => self::TODAY,
            $lastActiveAt->greaterThanOrEqualTo($now->copy()->subDays(7)) => self::THIS_WEEK,
            $lastActiveAt->greaterThanOrEqualTo($now->copy()->subDays(30)) => self::THIS_MONTH,
            default => null,
        };
    }
}
