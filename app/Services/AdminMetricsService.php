<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\LiveSession;
use App\Models\Payout;
use App\Models\TokenLedger;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Agregados do painel admin de receita (Sprint 16). SÓ números somados do ledger
 * e contadores — NENHUMA PII de membro. Dona única das consultas do dashboard:
 * o controller é fino e a Blade só desenha o que sai daqui.
 *
 * ── Sem PII (requisito de segurança) ─────────────────────────────────────────
 * Todas as métricas de token são SUM(amount) por entry_type num intervalo — o
 * ledger é apagado/anonimizado por titular no Hard Delete, mas aqui nunca se lê
 * uma LINHA nem um `user_id`, só a soma. Os contadores são `count()`. A única
 * lista com nomes é a de payouts (performers, não membros) e mostra stage_name +
 * valores — nunca PIX, CPF, e-mail. Membro não aparece em superfície nenhuma.
 */
class AdminMetricsService
{
    /**
     * Categorias de GASTO que o dashboard quebra (débito do membro, amount < 0).
     * label => entry_type. `spend_boost`/`spend_camera`/`spend_private`/
     * `spend_interest_unlock` existem mas não são as seis do escopo — ficam fora.
     */
    private const SPEND_TYPES = [
        'chat' => 'spend_chat_access',
        'gorjeta' => 'spend_tip',
        'presente' => 'spend_gift',
        'conteudo' => 'spend_content',
        'live' => 'spend_live',
        'chamada' => 'spend_call',
    ];

    /**
     * Receita de HOJE e dos ÚLTIMOS 30 DIAS. "Hoje" usa a fronteira do dia em São
     * Paulo (o admin pensa no dia BR); "30 dias" é janela rolante.
     *
     * @return array{today: array, last30: array}
     */
    public function revenue(): array
    {
        return [
            'today' => $this->revenueSince($this->todayStart()),
            'last30' => $this->revenueSince(now()->subDays(30)),
        ];
    }

    /**
     * @return array{tokens_sold:int, spent_by_type:array<string,int>, spent_total:int,
     *               tokens_paid_out:int, retention:int, gross_revenue_cents:int}
     */
    private function revenueSince(Carbon $since): array
    {
        $sold = $this->sumType('purchase', $since); // crédito, positivo

        $spentByType = [];
        foreach (self::SPEND_TYPES as $label => $type) {
            // Débitos são negativos no ledger; o painel mostra o valor gasto (abs).
            $spentByType[$label] = abs($this->sumType($type, $since));
        }

        // Payout LÍQUIDO: `payout_reserve` é o débito (negativo) na hora do saque;
        // `payout_reversal` devolve (positivo) num saque falho. A soma dos dois é o
        // que de fato SAIU — negativo —, então nega para o total pago (positivo).
        $paidOut = -($this->sumType('payout_reserve', $since) + $this->sumType('payout_reversal', $since));

        return [
            'tokens_sold' => $sold,
            'spent_by_type' => $spentByType,
            'spent_total' => array_sum($spentByType),
            'tokens_paid_out' => $paidOut,
            // Retenção da Limen em tokens: vendidos − pagos em payout.
            'retention' => $sold - $paidOut,
            // Receita bruta ESTIMADA: vendidos × preço médio do pacote por token.
            // É estimativa — não desconta o desconto por tier na compra (a Blade diz).
            'gross_revenue_cents' => (int) round($sold * $this->avgPricePerTokenCents()),
        ];
    }

    private function sumType(string $entryType, Carbon $since): int
    {
        return (int) TokenLedger::query()
            ->where('entry_type', $entryType)
            ->where('created_at', '>=', $since)
            ->sum('amount');
    }

    /** Preço médio (blended) do pacote por token, em centavos — fonte: config M.13.2. */
    private function avgPricePerTokenCents(): float
    {
        $packages = config('monetization.packages', []);
        $cents = array_sum(array_column($packages, 'price_cents'));
        $tokens = array_sum(array_column($packages, 'tokens'));

        return $tokens > 0 ? $cents / $tokens : 0.0;
    }

    /**
     * @return array{active_members:int, active_performers:int, lives_today:int, calls_today:int}
     */
    public function counters(): array
    {
        $sevenDaysAgo = now()->subDays(7);
        $todayStart = $this->todayStart();

        return [
            'active_members' => User::where('role', 'consumer')
                ->where('last_login_at', '>=', $sevenDaysAgo)->count(),
            'active_performers' => User::where('role', 'performer')
                ->where('last_login_at', '>=', $sevenDaysAgo)->count(),
            'lives_today' => LiveSession::where('created_at', '>=', $todayStart)->count(),
            // Chamada = 1:1 (type=private). Group show é outra coisa, fora do escopo.
            'calls_today' => CallSession::where('type', CallSession::TYPE_PRIVATE)
                ->where('created_at', '>=', $todayStart)->count(),
        ];
    }

    /**
     * Payouts parados em needs_review (status ENUM, não booleano). SÓ dados da
     * performer e valores — nunca PIX/CPF/e-mail. Sem membro (payout não tem).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingPayouts(): Collection
    {
        return Payout::query()
            ->where('status', 'needs_review')
            ->with('performer.performerProfile')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Payout $payout) => [
                'id' => $payout->id,
                'performer' => $payout->performer?->performerProfile?->stage_name ?? ('Performer #'.$payout->performer_id),
                'tokens' => (int) $payout->tokens,
                'amount_brl' => $payout->amount_brl,
                'period' => $payout->period_month
                    ? sprintf('%02d/%d', $payout->period_month, $payout->period_year)
                    : 'On-demand',
                'requested_at' => $payout->created_at,
            ]);
    }

    /** Fronteira do dia em São Paulo, em UTC (created_at é UTC; app.timezone é UTC). */
    private function todayStart(): Carbon
    {
        return Carbon::now('America/Sao_Paulo')->startOfDay()->utc();
    }
}
