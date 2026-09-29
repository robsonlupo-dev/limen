<?php

namespace App\Services;

use App\Models\PerformerProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Insights da performer (roadmap social, Onda 3 — § 3.2). Painel SÓ-LEITURA, tudo
 * AGREGADO. Dona única da agregação; deriva do que já existe (profile_visits,
 * follows, story_views, token_ledger, chat_access, content_unlocks) — não cria tabela.
 *
 * ── Anonimato é piso (não é detalhe de UI) ───────────────────────────────────────
 * A performer vê CONTAGENS e DISTRIBUIÇÕES, nunca "quem". Em especial os viewers de
 * story saem só como número (decisão do PO), coerente com reações/enquete e com o
 * cabeçalho de `StoryView` ("não existe lista de viewers"). Nenhum id/alias de membro
 * trafega deste serviço.
 *
 * ── Custo (servidor CX33, mas ainda assim disciplinado) ──────────────────────────
 * Cada métrica é um COUNT/SUM/GROUP indexado por (performer, janela); nada de varrer
 * linha a linha. O payload inteiro é CACHEADO por (performer, janela) por alguns
 * minutos — abrir o painel repetidas vezes não repete as queries.
 */
class PerformerInsightsService
{
    /** Janelas oferecidas, em dias. */
    public const WINDOWS = [7, 30];

    private const DEFAULT_WINDOW = 30;

    private const CACHE_TTL_MINUTES = 15;

    /**
     * Piso de volume para o painel mostrar DISTRIBUIÇÃO temporal (melhores horários)
     * e TAXAS de conversão do funil. Abaixo disso, um % ou uma faixa de hora vira
     * canal lateral de deanonimização (o inverso do que o piso de anonimato protege).
     */
    private const MIN_VOLUME_FOR_DISTRIBUTION = 30;

    /** Créditos que contam como GANHO vindo de membro (nunca bônus/grant/estorno). */
    private const EARNING_CREDIT_TYPES = [
        'tip_credit', 'chat_access_credit', 'content_credit', 'gift_credit',
        'call_credit', 'call_noshow_credit', 'live_credit',
    ];

    /**
     * Rótulo em FAIXA (banda) de uma contagem de PRESENÇA — a MESMA disciplina de
     * `PerformerProfile::followersLabelFor`: número exato de perfil pequeno é canal
     * lateral (o contador sobe de 3 para 4 no instante em que alguém segue/visita e
     * liga o evento à pessoa, desfazendo o Piso de Anonimato sem abrir lista nenhuma).
     * Toda métrica de presença (visitas, seguidores, views de story) sai por aqui.
     */
    private function band(int $count): string
    {
        return PerformerProfile::followersLabelFor($count);
    }

    public function forOwner(PerformerProfile $profile, int $days): array
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : self::DEFAULT_WINDOW;

        return Cache::remember(
            "insights:{$profile->id}:{$days}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->compute($profile, $days),
        );
    }

    private function compute(PerformerProfile $profile, int $days): array
    {
        $pid = $profile->id;
        $since = now()->subDays($days);
        $walletId = DB::table('token_wallets')->where('user_id', $profile->user_id)->value('id');
        // Fuso de exibição (SP), para "melhores horários" e a evolução diária baterem
        // com o relógio do público brasileiro. Offset dinâmico ('-03:00'): CONVERT_TZ
        // com offset numérico não depende das tabelas de fuso do MySQL.
        $offset = now(ProfileVisitService::DISPLAY_TIMEZONE)->format('P');

        // ── Visão geral ──────────────────────────────────────────────────────────
        // profile_visits usa `visited_at` (não created_at) como o instante da visita.
        $visits = DB::table('profile_visits')
            ->where('performer_profile_id', $pid)->where('visited_at', '>=', $since)->count();
        $uniqueVisitors = DB::table('profile_visits')
            ->where('performer_profile_id', $pid)->where('visited_at', '>=', $since)
            ->distinct()->count('visitor_id');
        $followersTotal = DB::table('follows')->where('performer_profile_id', $pid)->count();
        $followersNew = DB::table('follows')
            ->where('performer_profile_id', $pid)->where('created_at', '>=', $since)->count();
        // Story views: EXCLUI `exclusive` — audiência de exclusivo revela que os
        // viewers são Black/FC (a mesma razão do viewCount() devolver null para
        // exclusivo, decisão nº 3 do § 2.2). Só public/subscribers entram no número.
        $storyViews = DB::table('story_views')
            ->join('performer_stories', 'performer_stories.id', '=', 'story_views.performer_story_id')
            ->where('performer_stories.performer_profile_id', $pid)
            ->where('performer_stories.visibility_level', '!=', 'exclusive')
            ->where('story_views.viewed_at', '>=', $since)->count();
        $storyViewers = DB::table('story_views') // AGREGADO — nunca "quem viu"
            ->join('performer_stories', 'performer_stories.id', '=', 'story_views.performer_story_id')
            ->where('performer_stories.performer_profile_id', $pid)
            ->where('performer_stories.visibility_level', '!=', 'exclusive')
            ->where('story_views.viewed_at', '>=', $since)
            ->distinct()->count('story_views.user_id');
        $unlocks = DB::table('content_unlocks')
            ->join('performer_content', 'performer_content.id', '=', 'content_unlocks.performer_content_id')
            ->where('performer_content.performer_profile_id', $pid)
            ->where('content_unlocks.unlocked_at', '>=', $since)->count();
        $chatsStarted = DB::table('chat_access')
            ->where('performer_profile_id', $pid)->where('created_at', '>=', $since)->count();

        // ── Ganhos: total na janela + evolução diária (no fuso de exibição) ──────────
        $earningsTotal = 0.0;
        $earningsDaily = [];
        if ($walletId !== null) {
            $earningsTotal = (float) DB::table('token_ledger')
                ->where('wallet_id', $walletId)
                ->whereIn('entry_type', self::EARNING_CREDIT_TYPES)
                ->where('created_at', '>=', $since)->sum('amount');

            $earningsDaily = DB::table('token_ledger')
                ->selectRaw('DATE(CONVERT_TZ(created_at, ?, ?)) as d, SUM(amount) as total', ['+00:00', $offset])
                ->where('wallet_id', $walletId)
                ->whereIn('entry_type', self::EARNING_CREDIT_TYPES)
                ->where('created_at', '>=', $since)
                ->groupBy('d')->orderBy('d')
                ->get()
                ->map(fn ($r): array => ['date' => $r->d, 'tokens' => (float) $r->total])
                ->all();
        }

        // ── Funil visita → chat → compra (membros DISTINTOS na janela) ──────────────
        // Três ações cada vez mais comprometidas: viu o perfil → abriu chat pago →
        // desbloqueou conteúdo. Tudo contagem distinta; nenhum id sai daqui.
        $funnelChatted = DB::table('chat_access')
            ->where('performer_profile_id', $pid)->where('created_at', '>=', $since)
            ->distinct()->count('member_id');
        $funnelBought = DB::table('content_unlocks')
            ->join('performer_content', 'performer_content.id', '=', 'content_unlocks.performer_content_id')
            ->where('performer_content.performer_profile_id', $pid)
            ->where('content_unlocks.unlocked_at', '>=', $since)
            ->distinct()->count('content_unlocks.user_id');

        // ── Melhores horários: distribuição de VISITAS em 4 FAIXAS de 6h (fuso SP) ──
        // NUNCA hora a hora com número exato — isso é mais fino que a faixa de 6h usada
        // no resto do produto e vira canal lateral. Mostra só a PARTICIPAÇÃO (%) de
        // cada faixa, e só quando há volume mínimo (senão a distribuição de poucas
        // visitas aponta o horário de uma pessoa).
        $byHourRows = DB::table('profile_visits')
            ->selectRaw('HOUR(CONVERT_TZ(visited_at, ?, ?)) as h, COUNT(*) as c', ['+00:00', $offset])
            ->where('performer_profile_id', $pid)->where('visited_at', '>=', $since)
            ->groupBy('h')->pluck('c', 'h')->all();

        $periodDefs = [
            ['label' => 'Madrugada', 'from' => 0, 'to' => 5],
            ['label' => 'Manhã', 'from' => 6, 'to' => 11],
            ['label' => 'Tarde', 'from' => 12, 'to' => 17],
            ['label' => 'Noite', 'from' => 18, 'to' => 23],
        ];
        $periodBuckets = [];
        foreach ($periodDefs as $p) {
            $sum = 0;
            for ($h = $p['from']; $h <= $p['to']; $h++) {
                $sum += (int) ($byHourRows[$h] ?? 0);
            }
            $periodBuckets[] = ['label' => $p['label'], 'count' => $sum];
        }
        $periodsTotal = array_sum(array_column($periodBuckets, 'count'));
        $periodsAvailable = $periodsTotal >= self::MIN_VOLUME_FOR_DISTRIBUTION;
        $periods = array_map(fn (array $b): array => [
            'label' => $b['label'],
            'share' => $periodsTotal > 0 ? (int) round(($b['count'] / $periodsTotal) * 100) : 0,
        ], $periodBuckets);

        // Taxas de conversão do funil: só com base suficiente (senão 1/1 = 100% aponta
        // uma pessoa). Números absolutos de PRESENÇA saem em FAIXA; as ações PAGAS
        // (chat/compra = transações da própria performer) saem exatas.
        $ratesAvailable = $uniqueVisitors >= self::MIN_VOLUME_FOR_DISTRIBUTION;

        return [
            'days' => $days,
            // Presença → SEMPRE em faixa (banda), nunca número exato.
            'overview' => [
                'visits' => $this->band($visits),
                'unique_visitors' => $this->band($uniqueVisitors),
                'followers_total' => $this->band($followersTotal),
                'followers_new' => $this->band($followersNew),
                'story_views' => $this->band($storyViews),
                'story_viewers' => $this->band($storyViewers),
                // Ações PAGAS (transações da performer) — exatas, é a receita dela.
                'unlocks' => $unlocks,
                'chats_started' => $chatsStarted,
            ],
            'earnings' => ['total' => $earningsTotal, 'daily' => $earningsDaily],
            'funnel' => [
                'visited' => $this->band($uniqueVisitors),
                'chatted' => $funnelChatted,
                'bought' => $funnelBought,
                // % relativos aos visitantes, só com base ≥ piso (senão null → a UI
                // mostra "—" em vez de um % que aponta poucos indivíduos).
                'chat_rate' => $ratesAvailable ? (int) round(($funnelChatted / $uniqueVisitors) * 100) : null,
                'buy_rate' => $ratesAvailable ? (int) round(($funnelBought / $uniqueVisitors) * 100) : null,
            ],
            'periods' => ['available' => $periodsAvailable, 'buckets' => $periods],
        ];
    }
}
