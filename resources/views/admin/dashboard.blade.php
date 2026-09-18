<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Painel — Limen</title>
    <style>
        :root {
            color-scheme: dark;
            --limen-bg: #181410;
            --limen-surface: #241e16;
            --limen-surface-2: #2b241b;
            --limen-ink: #f0e9dc;
            --limen-ink-soft: #a89a82;
            --limen-ink-mute: #8a8175;
            --limen-gold: #d6b872;
            --limen-line: #2e2820;
            --limen-green: #4ade80;
            --limen-red: #f87171;
            --limen-blue: #60a5fa;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--limen-bg); color: var(--limen-ink);
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               line-height: 1.5; -webkit-font-smoothing: antialiased; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 32px 20px 64px; }
        .header { margin-bottom: 28px; }
        .header h1 { font-weight: 700; font-size: 26px; letter-spacing: -0.01em; }
        .header .sub { color: var(--limen-ink-mute); font-size: 13px; margin-top: 2px; }
        .nav { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 32px; }
        .nav a { text-decoration: none; font-size: 13px; padding: 7px 14px; border-radius: 8px;
                 border: 1px solid var(--limen-line); color: var(--limen-ink-mute);
                 transition: all .15s ease; }
        .nav a:hover { border-color: var(--limen-ink-mute); color: var(--limen-ink-soft); }
        .nav a.on { border-color: var(--limen-gold); color: var(--limen-gold);
                    background: rgba(214, 184, 114, 0.06); }
        .section { margin-bottom: 32px; }
        .section-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .section-head h2 { font-weight: 600; font-size: 14px; color: var(--limen-gold);
                           text-transform: uppercase; letter-spacing: 0.07em; }
        .section-head .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px;
                               border: 1px solid var(--limen-line); color: var(--limen-ink-mute); }
        .card { border: 1px solid var(--limen-line); border-radius: 12px; padding: 20px;
                background: var(--limen-surface); }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .grid-6 { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; }
        .metric { border: 1px solid var(--limen-line); border-radius: 10px; padding: 14px 16px;
                  background: var(--limen-surface-2); }
        .metric .label { color: var(--limen-ink-mute); font-size: 11px; text-transform: uppercase;
                         letter-spacing: 0.05em; margin-bottom: 6px; }
        .metric .value { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .metric .value.gold { color: var(--limen-gold); }
        .metric .value.green { color: var(--limen-green); }
        .metric .unit { font-size: 11px; color: var(--limen-ink-mute); font-weight: 400; margin-left: 2px; }
        .metric .detail { font-size: 12px; color: var(--limen-ink-mute); margin-top: 4px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 10px; font-size: 13px;
                 border-bottom: 1px solid var(--limen-line); }
        th { color: var(--limen-ink-mute); font-weight: 500; font-size: 11px;
             text-transform: uppercase; letter-spacing: 0.04em; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .empty { color: var(--limen-ink-mute); font-size: 13px; padding: 8px 0; }
        .split-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .split-bar .performer { background: var(--limen-gold); }
        .split-bar .platform { background: var(--limen-ink-mute); }
        .pill { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
                border: 1px solid var(--limen-gold); color: var(--limen-gold); }
        .status-warn { color: var(--limen-red); }
        .health-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%;
                      margin-right: 6px; vertical-align: middle; }
        .health-dot.ok { background: var(--limen-green); }
        .health-dot.warn { background: var(--limen-red); }
        .est { color: var(--limen-ink-mute); font-size: 12px; margin-top: 10px; }
        @media (max-width: 900px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-6 { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 600px) {
            .grid-4, .grid-3, .grid-6 { grid-template-columns: 1fr; }
            .wrap { padding: 20px 16px 48px; }
        }
    </style>
</head>
<body>
@php
    $tok = fn ($n) => number_format((int) $n, 0, ',', '.');
    $brl = fn ($cents) => 'R$ ' . number_format(((int) $cents) / 100, 2, ',', '.');
    $pct = fn ($rate) => number_format($rate * 100, 0) . '%';
    $spendLabels = ['chat' => 'Chat', 'gorjeta' => 'Gorjeta', 'presente' => 'Presente',
                    'conteudo' => 'Conteúdo', 'live' => 'Live', 'chamada' => 'Chamada'];
    $periods = ['today' => 'Hoje', 'last30' => 'Últimos 30 dias'];
@endphp

<div class="wrap">

    <div class="header">
        <h1>Painel Admin</h1>
        <p class="sub">Agregados do ledger, contadores e saúde da plataforma. Sem dados de membros.</p>
    </div>

    <nav class="nav">
        <a href="{{ route('admin.dashboard') }}" class="on">Painel</a>
        <a href="{{ route('admin.kyc.panel') }}">KYC</a>
        <a href="{{ route('admin.reports') }}">Denúncias</a>
        <a href="{{ route('admin.waitlist') }}">Waitlist</a>
    </nav>

    {{-- VISÃO GERAL --}}
    <div class="section">
        <div class="section-head"><h2>Visão Geral da Plataforma</h2></div>
        <div class="grid-4" style="margin-bottom: 12px;">
            <div class="metric"><div class="label">Total de Membros</div><div class="value gold">{{ $tok($platform['total_members']) }}</div></div>
            <div class="metric"><div class="label">Total de Performers</div><div class="value gold">{{ $tok($platform['total_performers']) }}</div></div>
            <div class="metric"><div class="label">Total de Usuários</div><div class="value">{{ $tok($platform['total_users']) }}</div></div>
            <div class="metric"><div class="label">Waitlist</div><div class="value">{{ $tok($platform['waitlist_total']) }}</div></div>
        </div>
        <div class="grid-4" style="margin-bottom: 12px;">
            <div class="metric"><div class="label">Novos membros hoje</div><div class="value green">{{ $tok($platform['new_members_today']) }}</div></div>
            <div class="metric"><div class="label">Novos membros 7d</div><div class="value">{{ $tok($platform['new_members_7d']) }}</div></div>
            <div class="metric"><div class="label">Novos membros 30d</div><div class="value">{{ $tok($platform['new_members_30d']) }}</div></div>
            <div class="metric"><div class="label">Novas performers 7d</div><div class="value">{{ $tok($platform['new_performers_7d']) }}</div></div>
        </div>
        <div class="grid-4">
            <div class="metric"><div class="label">Performers ativas</div><div class="value green">{{ $tok($platform['performers_active']) }}</div></div>
            <div class="metric"><div class="label">Performers pendente KYC</div><div class="value">{{ $tok($platform['performers_pending']) }}</div></div>
            <div class="metric"><div class="label">Performers banidas</div><div class="value" style="color: var(--limen-red);">{{ $tok($platform['performers_banned']) }}</div></div>
            <div class="metric"><div class="label">Denúncias abertas</div><div class="value" style="color: {{ $platform['open_reports'] > 0 ? 'var(--limen-red)' : 'var(--limen-green)' }};">{{ $tok($platform['open_reports']) }}</div></div>
        </div>
    </div>

    {{-- ATIVIDADE --}}
    <div class="section">
        <div class="section-head"><h2>Atividade</h2></div>
        <div class="grid-4">
            <div class="metric"><div class="label">Membros ativos (7d)</div><div class="value gold">{{ $tok($counters['active_members']) }}</div></div>
            <div class="metric"><div class="label">Performers ativas (7d)</div><div class="value gold">{{ $tok($counters['active_performers']) }}</div></div>
            <div class="metric"><div class="label">Lives hoje</div><div class="value">{{ $tok($counters['lives_today']) }}</div></div>
            <div class="metric"><div class="label">Chamadas hoje</div><div class="value">{{ $tok($counters['calls_today']) }}</div></div>
        </div>
    </div>

    {{-- RECEITA --}}
    <div class="section">
        <div class="section-head"><h2>Receita</h2></div>
        @foreach ($periods as $key => $label)
            @php $r = $revenue[$key]; @endphp
            <div class="card" style="margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
                    <h2 style="margin: 0; font-size: 13px;">{{ $label }}</h2>
                    @if ($r['is_estimate'])
                        <span style="font-size: 10px; padding: 1px 6px; border-radius: 999px; border: 1px solid var(--limen-ink-mute); color: var(--limen-ink-mute);">Estimada</span>
                    @endif
                </div>
                <div class="grid-4" style="margin-bottom: 12px;">
                    <div class="metric"><div class="label">Receita bruta</div><div class="value gold">{{ $brl($r['gross_revenue_cents']) }}</div></div>
                    <div class="metric"><div class="label">Tokens vendidos</div><div class="value">{{ $tok($r['tokens_sold']) }}</div></div>
                    <div class="metric"><div class="label">Tokens pagos (payout)</div><div class="value">{{ $tok($r['tokens_paid_out']) }}</div></div>
                    <div class="metric"><div class="label">Retenção Limen</div><div class="value green">{{ $tok($r['retention']) }} <span class="unit">tk</span></div></div>
                </div>
                <div class="grid-6">
                    @foreach ($spendLabels as $skey => $slabel)
                        <div class="metric"><div class="label">{{ $slabel }}</div><div class="value">{{ $tok($r['spent_by_type'][$skey] ?? 0) }} <span class="unit">tk</span></div></div>
                    @endforeach
                </div>
                @if ($r['is_estimate'])
                    <p class="est">Receita = tokens vendidos x preço médio do pacote. Estimativa.</p>
                @else
                    <p class="est">Receita = soma das cobranças confirmadas (Asaas/PIX). Valor real.</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- SPLITS --}}
    <div class="section">
        <div class="section-head"><h2>Splits por Vertical</h2><span class="badge">últimos 30 dias</span></div>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Vertical</th><th class="num">Gasto total</th><th class="num">Performer</th><th class="num">Plataforma</th><th class="num">Split</th><th style="width: 120px;"></th></tr></thead>
                    <tbody>
                        @foreach ($splits as $label => $s)
                            <tr>
                                <td>{{ $spendLabels[$label] ?? $label }}</td>
                                <td class="num">{{ $tok($s['spent']) }} <span class="unit">tk</span></td>
                                <td class="num" style="color: var(--limen-gold);">{{ $tok($s['performer']) }}</td>
                                <td class="num">{{ $tok($s['platform']) }}</td>
                                <td class="num">{{ $pct($s['rate']) }} / {{ $pct(1 - $s['rate']) }}</td>
                                <td><div class="split-bar"><div class="performer" style="width: {{ $s['rate'] * 100 }}%;"></div><div class="platform" style="width: {{ (1 - $s['rate']) * 100 }}%;"></div></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- LEDGER HEALTH --}}
    <div class="section">
        <div class="section-head"><h2>Integridade do Ledger</h2></div>
        <div class="card">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <span class="health-dot {{ $ledgerHealth['ok'] ? 'ok' : 'warn' }}"></span>
                <span style="font-size: 15px; font-weight: 600; {{ $ledgerHealth['ok'] ? 'color: var(--limen-green);' : 'color: var(--limen-red);' }}">
                    {{ $ledgerHealth['ok'] ? 'Saudável' : 'Divergências encontradas' }}
                </span>
                <span style="color: var(--limen-ink-mute); font-size: 13px;">{{ $tok($ledgerHealth['checked']) }} wallets verificadas</span>
            </div>
            @if (!$ledgerHealth['ok'])
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Wallet</th><th class="num">Saldo (wallet)</th><th class="num">Soma (ledger)</th><th class="num">Diferença</th></tr></thead>
                        <tbody>
                            @foreach ($ledgerHealth['divergences'] as $d)
                                <tr><td>#{{ $d['wallet_id'] }}</td><td class="num">{{ $tok($d['wallet_balance']) }}</td><td class="num">{{ $tok($d['ledger_sum']) }}</td><td class="num status-warn">{{ $tok($d['diff']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="empty">wallet.balance == SUM(ledger.amount) para todas as wallets.</p>
            @endif
        </div>
    </div>

    {{-- FILAS PENDENTES --}}
    <div class="section">
        <div class="section-head"><h2>Filas Pendentes</h2></div>
        <div class="grid-3">
            <div class="metric">
                <div class="label">KYC pendente</div>
                <div class="value" style="color: {{ $platform['pending_kyc'] > 0 ? 'var(--limen-gold)' : 'var(--limen-green)' }};">{{ $tok($platform['pending_kyc']) }}</div>
                @if ($platform['pending_kyc'] > 0)
                    <div class="detail"><a href="{{ route('admin.kyc.panel') }}" style="color: var(--limen-gold); text-decoration: none;">Ver fila &rarr;</a></div>
                @endif
            </div>
            <div class="metric">
                <div class="label">Denúncias abertas</div>
                <div class="value" style="color: {{ $platform['open_reports'] > 0 ? 'var(--limen-red)' : 'var(--limen-green)' }};">{{ $tok($platform['open_reports']) }}</div>
                @if ($platform['open_reports'] > 0)
                    <div class="detail"><a href="{{ route('admin.reports') }}" style="color: var(--limen-gold); text-decoration: none;">Ver fila &rarr;</a></div>
                @endif
            </div>
            <div class="metric"><div class="label">Novas performers hoje</div><div class="value">{{ $tok($platform['new_performers_today']) }}</div></div>
        </div>
    </div>

    {{-- PAYOUTS --}}
    <div class="section">
        <div class="section-head">
            <h2>Payouts Pendentes de Revisão</h2>
            @if ($pendingPayouts->isNotEmpty())
                <span class="badge" style="border-color: var(--limen-red); color: var(--limen-red);">{{ $pendingPayouts->count() }}</span>
            @endif
        </div>
        <div class="card">
            @if ($pendingPayouts->isEmpty())
                <p class="empty">Nenhum payout em revisão.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Performer</th><th class="num">Tokens</th><th class="num">Valor (R$)</th><th>Período</th><th>Solicitado</th></tr></thead>
                        <tbody>
                            @foreach ($pendingPayouts as $payout)
                                <tr>
                                    <td>{{ $payout['id'] }}</td>
                                    <td>{{ $payout['performer'] }}</td>
                                    <td class="num">{{ $tok($payout['tokens']) }}</td>
                                    <td class="num">R$ {{ number_format((float) $payout['amount_brl'], 2, ',', '.') }}</td>
                                    <td><span class="pill">{{ $payout['period'] }}</span></td>
                                    <td>{{ optional($payout['requested_at'])->format('d/m/Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- STRIKES --}}
    <div class="section">
        <div class="section-head">
            <h2>Performers em Revisão por No-Show</h2>
            @if ($strikeReviewPerformers->isNotEmpty())
                <span class="badge" style="border-color: var(--limen-red); color: var(--limen-red);">{{ $strikeReviewPerformers->count() }}</span>
            @endif
        </div>
        <div class="card">
            @if ($strikeReviewPerformers->isEmpty())
                <p class="empty">Nenhuma performer no limiar de strikes.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Performer</th><th class="num">Strikes</th></tr></thead>
                        <tbody>
                            @foreach ($strikeReviewPerformers as $row)
                                <tr><td>{{ $row['id'] }}</td><td>{{ $row['performer'] }}</td><td class="num" style="color: var(--limen-red);">{{ $row['strikes'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</div>
</body>
</html>
