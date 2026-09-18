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
     * Série DIÁRIA de tokens vendidos × gastos, para o gráfico "Vendidos × gastos"
     * do dashboard (uma barra por dia, últimos $days dias). Antes o gráfico só
     * tinha o total agregado por categoria; agora mostra a evolução no tempo.
     *
     * Fronteira de dia em São Paulo (o admin pensa no dia BR): agrupa por
     * DATE(CONVERT_TZ(created_at, '+00:00', '-03:00')). O offset numérico NÃO
     * depende das tz tables do MySQL (que podem não estar carregadas) — Brasil
     * não tem mais horário de verão desde 2019, então -03:00 fixo está correto.
     *
     * "Vendidos" = SUM(purchase) do dia. "Gastos" = |SUM| dos seis SPEND_TYPES
     * do dia (débitos são negativos no ledger; o gráfico mostra o valor gasto).
     * Sempre devolve $days linhas contíguas (dias sem lançamento vêm com 0), na
     * ordem cronológica — o front desenha na ordem que recebe.
     *
     * @return list<array{date:string, sold:int, spent:int}>
     */
    public function dailySalesVsSpend(int $days = 12): array
    {
        $spendTypes = array_values(self::SPEND_TYPES);
        $tz = "'+00:00', '-03:00'";
        // Início da janela: começo do primeiro dia (SP), convertido para UTC.
        $since = Carbon::now('America/Sao_Paulo')->startOfDay()->subDays($days - 1)->utc();

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

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now('America/Sao_Paulo')->startOfDay()->subDays($i);
            $key = $d->format('Y-m-d');
            $series[] = [
                'date' => $d->format('d/m'),
                'sold' => (int) round((float) ($soldByDay[$key] ?? 0)),
                'spent' => (int) abs(round((float) ($spentByDay[$key] ?? 0))),
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
