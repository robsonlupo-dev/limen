<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\IdentityVerification;
use App\Models\LiveSession;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PerformerProfile;
use App\Models\Report;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregados do painel admin de receita (Sprint 16, expandido). SÓ números somados
 * do ledger e contadores — NENHUMA PII de membro. Dona única das consultas do
 * dashboard: o controller é fino e a Blade só desenha o que sai daqui.
 */
class AdminMetricsService
{
    private const SPEND_TYPES = [
        'chat' => 'spend_chat_access',
        'gorjeta' => 'spend_tip',
        'presente' => 'spend_gift',
        'conteudo' => 'spend_content',
        'live' => 'spend_live',
        'chamada' => 'spend_call',
    ];

    private const VERTICAL_SPLITS = [
        'chat'     => ['rate' => 0.80, 'spend' => 'spend_chat_access', 'credit' => 'chat_credit'],
        'gorjeta'  => ['rate' => 0.80, 'spend' => 'spend_tip',         'credit' => 'tip_credit'],
        'presente' => ['rate' => 0.80, 'spend' => 'spend_gift',        'credit' => 'gift_credit'],
        'conteudo' => ['rate' => 0.80, 'spend' => 'spend_content',     'credit' => 'content_credit'],
        'live'     => ['rate' => 0.70, 'spend' => 'spend_live',        'credit' => 'live_credit'],
        'chamada'  => ['rate' => 0.70, 'spend' => 'spend_call',        'credit' => 'call_credit'],
    ];

    public function revenue(): array
    {
        return [
            'today' => $this->revenueSince($this->todayStart()),
            'last30' => $this->revenueSince(now()->subDays(30)),
        ];
    }

    private function revenueSince(Carbon $since): array
    {
        $sold = $this->sumType('purchase', $since);

        $spentByType = [];
        foreach (self::SPEND_TYPES as $label => $type) {
            $spentByType[$label] = abs($this->sumType($type, $since));
        }

        $paidOut = -($this->sumType('payout_reserve', $since) + $this->sumType('payout_reversal', $since));

        $realRevenueCents = $this->realRevenueSince($since);
        $isEstimate = $realRevenueCents <= 0;

        return [
            'tokens_sold' => $sold,
            'spent_by_type' => $spentByType,
            'spent_total' => array_sum($spentByType),
            'tokens_paid_out' => $paidOut,
            'retention' => $sold - $paidOut,
            'gross_revenue_cents' => $isEstimate
                ? (int) round($sold * $this->avgPricePerTokenCents())
                : $realRevenueCents,
            'is_estimate' => $isEstimate,
        ];
    }

    private function sumType(string $entryType, Carbon $since): int
    {
        return (int) TokenLedger::query()
            ->where('entry_type', $entryType)
            ->where('created_at', '>=', $since)
            ->sum('amount');
    }

    private function realRevenueSince(Carbon $since): int
    {
        return (int) Payment::query()
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $since)
            ->sum('amount_cents');
    }

    private function avgPricePerTokenCents(): float
    {
        $packages = config('monetization.packages', []);
        $cents = array_sum(array_column($packages, 'price_cents'));
        $tokens = array_sum(array_column($packages, 'tokens'));

        return $tokens > 0 ? $cents / $tokens : 0.0;
    }

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
            'calls_today' => CallSession::where('type', CallSession::TYPE_PRIVATE)
                ->where('created_at', '>=', $todayStart)->count(),
        ];
    }

    /**
     * Janelas de período que o toggle do dashboard oferece: rótulo => nº de dias.
     * 'ano' = 365 dias corridos (não o ano-calendário — janela rolante, como o 30/90).
     */
    public const PERIODS = ['30' => 30, '90' => 90, 'ano' => 365];

    /** Normaliza a chave de período vinda da query (?period=) para uma das PERIODS. */
    public static function periodDays(?string $period): int
    {
        return self::PERIODS[$period] ?? self::PERIODS['30'];
    }

    /**
     * Receita/gastos agregados de uma janela rolante de $days dias — mesma forma
     * de revenue()['last30'], mas com a janela escolhida no toggle (30/90/ano).
     *
     * @return array{tokens_sold:int, spent_by_type:array<string,int>, spent_total:int,
     *               tokens_paid_out:int, retention:int, gross_revenue_cents:int, is_estimate:bool}
     */
    public function periodRevenue(int $days): array
    {
        return $this->revenueSince(now()->subDays($days));
    }

    /**
     * Série de tokens vendidos × gastos para o gráfico do dashboard, agrupada em
     * ~$buckets blocos contíguos ao longo dos últimos $days dias (o design usa
     * "blocos de 2–3 dias" em 30 dias; em 90/ano os blocos ficam mais largos, mas
     * o gráfico continua com ~12 barras legíveis). Quando $days <= $buckets cada
     * bloco é um dia (comportamento diário).
     *
     * Fronteira de dia em São Paulo via DATE(CONVERT_TZ(created_at,'+00:00','-03:00')):
     * offset numérico NÃO depende das tz tables do MySQL. Brasil sem horário de
     * verão desde 2019, então -03:00 fixo está correto. "Vendidos" = SUM(purchase);
     * "Gastos" = |SUM| dos seis SPEND_TYPES. Dias sem lançamento contam 0. Sempre
     * devolve blocos contíguos em ordem cronológica.
     *
     * @return list<array{date:string, sold:int, spent:int}>
     */
    public function salesVsSpendSeries(int $days = 12, int $buckets = 12): array
    {
        $days = max(1, $days);
        $buckets = max(1, min($buckets, $days));
        $spendTypes = array_values(self::SPEND_TYPES);
        $tz = "'+00:00', '-03:00'";

        $startDay = Carbon::now('America/Sao_Paulo')->startOfDay()->subDays($days - 1);
        $since = $startDay->copy()->utc();

        $soldByDay = TokenLedger::query()
            ->where('entry_type', 'purchase')
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(CONVERT_TZ(created_at, {$tz})) as day, SUM(amount) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        $spentByDay = TokenLedger::query()
            ->whereIn('entry_type', $spendTypes)
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(CONVERT_TZ(created_at, {$tz})) as day, SUM(amount) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        // Distribui os $days em $buckets blocos contíguos o mais uniformes possível
        // (os primeiros blocos recebem o dia extra da sobra).
        $base = intdiv($days, $buckets);
        $rem = $days % $buckets;

        $series = [];
        $cursor = $startDay->copy();
        for ($b = 0; $b < $buckets; $b++) {
            $len = $base + ($b < $rem ? 1 : 0);
            $first = $cursor->copy();
            $sold = 0;
            $spent = 0;
            for ($k = 0; $k < $len; $k++) {
                $key = $cursor->format('Y-m-d');
                $sold += (int) round((float) ($soldByDay[$key] ?? 0));
                $spent += (int) abs(round((float) ($spentByDay[$key] ?? 0)));
                $cursor->addDay();
            }
            $last = $cursor->copy()->subDay();
            $series[] = [
                'date' => $len === 1 ? $first->format('d/m') : $first->format('d/m').'–'.$last->format('d/m'),
                'sold' => $sold,
                'spent' => $spent,
            ];
        }

        return $series;
    }

    public function platform(): array
    {
        $todayStart = $this->todayStart();
        $sevenDaysAgo = now()->subDays(7);
        $thirtyDaysAgo = now()->subDays(30);

        return [
            'total_members'    => User::where('role', 'consumer')->count(),
            'total_performers' => User::where('role', 'performer')->count(),
            'total_users'      => User::count(),

            'new_members_today'  => User::where('role', 'consumer')
                ->where('created_at', '>=', $todayStart)->count(),
            'new_members_7d'     => User::where('role', 'consumer')
                ->where('created_at', '>=', $sevenDaysAgo)->count(),
            'new_members_30d'    => User::where('role', 'consumer')
                ->where('created_at', '>=', $thirtyDaysAgo)->count(),

            'new_performers_today' => User::where('role', 'performer')
                ->where('created_at', '>=', $todayStart)->count(),
            'new_performers_7d'    => User::where('role', 'performer')
                ->where('created_at', '>=', $sevenDaysAgo)->count(),

            'performers_active'  => User::where('role', 'performer')
                ->where('status', 'active')->count(),
            'performers_pending' => User::where('role', 'performer')
                ->where('status', 'pending_kyc')->count(),
            'performers_banned'  => User::where('role', 'performer')
                ->where('status', 'banned')->count(),

            'pending_kyc'     => IdentityVerification::where('status', 'pending')->count(),
            'open_reports'    => Report::pending()->count(),
            'waitlist_total'  => WaitlistEntry::count(),
        ];
    }

    public function splits(): array
    {
        $since = now()->subDays(30);
        $result = [];

        foreach (self::VERTICAL_SPLITS as $label => $config) {
            $spent = abs($this->sumType($config['spend'], $since));
            $performerRate = $config['rate'];

            $result[$label] = [
                'spent'     => $spent,
                'performer' => (int) round($spent * $performerRate),
                'platform'  => (int) round($spent * (1 - $performerRate)),
                'rate'      => $performerRate,
            ];
        }

        return $result;
    }

    public function ledgerHealth(): array
    {
        $divergences = DB::select("
            SELECT
                tw.id AS wallet_id,
                tw.user_id,
                tw.balance AS wallet_balance,
                COALESCE(ledger.total, 0) AS ledger_sum,
                (tw.balance - COALESCE(ledger.total, 0)) AS diff
            FROM token_wallets tw
            LEFT JOIN (
                SELECT wallet_id, SUM(amount) AS total
                FROM token_ledger
                GROUP BY wallet_id
            ) ledger ON ledger.wallet_id = tw.id
            WHERE ABS(tw.balance - COALESCE(ledger.total, 0)) > 0.001
            LIMIT 10
        ");

        $checked = TokenWallet::count();

        return [
            'ok'          => count($divergences) === 0,
            'checked'     => $checked,
            'divergences' => array_map(fn ($row) => [
                'wallet_id'      => $row->wallet_id,
                'user_id'        => $row->user_id,
                'wallet_balance' => $row->wallet_balance,
                'ledger_sum'     => $row->ledger_sum,
                'diff'           => $row->diff,
            ], $divergences),
        ];
    }

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
                'tokens' => $payout->tokens,
                'amount_brl' => $payout->amount_brl,
                'period' => $payout->period_month
                    ? sprintf('%02d/%d', $payout->period_month, $payout->period_year)
                    : 'On-demand',
                'requested_at' => $payout->created_at,
            ]);
    }

    public function strikeReviewPerformers(): Collection
    {
        $threshold = (int) config('scheduled_call.strike_review_threshold');

        return PerformerProfile::query()
            ->where('noshow_strike_count', '>=', $threshold)
            ->orderByDesc('noshow_strike_count')
            ->get()
            ->map(fn (PerformerProfile $profile) => [
                'id' => $profile->id,
                'performer' => $profile->stage_name ?? ('Performer #'.$profile->id),
                'strikes' => (int) $profile->noshow_strike_count,
            ]);
    }

    private function todayStart(): Carbon
    {
        return Carbon::now('America/Sao_Paulo')->startOfDay()->utc();
    }
}
