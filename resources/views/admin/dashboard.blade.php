<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Painel de Receita — Limen</title>
    <style>
        /* Paleta maison (design tokens limen-*). Estas páginas admin são Blade
           standalone (não carregam o bundle Vite/Tailwind), então os tokens entram
           como CSS custom properties com os MESMOS valores de resources/css/app.css. */
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
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--limen-bg); color: var(--limen-ink);
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 40px 20px 64px; }
        h1 { font-weight: 600; font-size: 24px; margin: 0 0 4px; }
        h2 { font-weight: 600; font-size: 15px; margin: 0 0 14px; color: var(--limen-gold);
             text-transform: uppercase; letter-spacing: 0.06em; }
        .sub { color: var(--limen-ink-mute); font-size: 13px; margin: 0 0 24px; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; }
        .nav a { text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 8px;
                 border: 1px solid var(--limen-line); color: var(--limen-ink-mute); }
        .nav a.on { border-color: var(--limen-gold); color: var(--limen-gold); }
        .card { border: 1px solid var(--limen-line); border-radius: 12px; padding: 22px;
                background: var(--limen-surface); margin-top: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .metric { border: 1px solid var(--limen-line); border-radius: 10px; padding: 14px 16px;
                  background: var(--limen-surface-2); }
        .metric .label { color: var(--limen-ink-mute); font-size: 12px; text-transform: uppercase;
                         letter-spacing: 0.04em; margin-bottom: 6px; }
        .metric .value { font-size: 22px; font-weight: 600; }
        .metric .value.gold { color: var(--limen-gold); }
        .metric .unit { font-size: 12px; color: var(--limen-ink-mute); font-weight: 400; }
        .est { color: var(--limen-ink-mute); font-size: 12px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; font-size: 14px;
                 border-bottom: 1px solid var(--limen-line); }
        th { color: var(--limen-ink-mute); font-weight: 500; font-size: 12px; text-transform: uppercase; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .empty { color: var(--limen-ink-mute); font-size: 14px; }
        .pill { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
                border: 1px solid var(--limen-gold); color: var(--limen-gold); }
    </style>
</head>
<body>
@php
    // Helpers de formatação (sem PII — só números).
    $tok = fn ($n) => number_format((int) $n, 0, ',', '.');
    $brl = fn ($cents) => 'R$ ' . number_format(((int) $cents) / 100, 2, ',', '.');
    $spendLabels = ['chat' => 'Chat', 'gorjeta' => 'Gorjeta', 'presente' => 'Presente',
                    'conteudo' => 'Conteúdo', 'live' => 'Live', 'chamada' => 'Chamada'];
    $periods = ['today' => 'Hoje', 'last30' => 'Últimos 30 dias'];
@endphp
    <div class="wrap">
        <h1>Painel de Receita</h1>
        <p class="sub">Agregados do ledger e contadores. Sem dados de membros — só números.</p>

        <nav class="nav">
            <a href="{{ route('admin.dashboard') }}" class="on">Painel</a>
            <a href="{{ route('admin.kyc.panel') }}">KYC</a>
            <a href="{{ route('admin.reports') }}">Denúncias</a>
            <a href="{{ route('admin.waitlist') }}">Waitlist</a>
        </nav>

        {{-- ── Receita (hoje / 30 dias) ─────────────────────────────────────── --}}
        @foreach ($periods as $key => $label)
            @php $r = $revenue[$key]; @endphp
            <div class="card">
                <h2>{{ $label }}</h2>
                <div class="grid">
                    <div class="metric">
                        <div class="label">Tokens vendidos</div>
                        <div class="value gold">{{ $tok($r['tokens_sold']) }}</div>
                    </div>
                    <div class="metric">
                        <div class="label">Tokens pagos (payout, líquido)</div>
                        <div class="value">{{ $tok($r['tokens_paid_out']) }}</div>
                    </div>
                    <div class="metric">
                        <div class="label">Retenção Limen</div>
                        <div class="value">{{ $tok($r['retention']) }} <span class="unit">tokens</span></div>
                    </div>
                    <div class="metric">
                        <div class="label">Receita bruta{{ $r['is_estimate'] ? ' (estimada)' : '' }}</div>
                        <div class="value gold">{{ $brl($r['gross_revenue_cents']) }}</div>
                    </div>
                    <div class="metric">
                        <div class="label">Total gasto</div>
                        <div class="value">{{ $tok($r['spent_total']) }} <span class="unit">tokens</span></div>
                    </div>
                </div>

                <div class="grid" style="margin-top: 12px;">
                    @foreach ($spendLabels as $skey => $slabel)
                        <div class="metric">
                            <div class="label">Gasto · {{ $slabel }}</div>
                            <div class="value">{{ $tok($r['spent_by_type'][$skey] ?? 0) }} <span class="unit">tk</span></div>
                        </div>
                    @endforeach
                </div>

                @if ($r['is_estimate'])
                    <p class="est">
                        Receita bruta = tokens vendidos × preço médio do pacote por token.
                        Estimativa — não desconta o desconto por tier na compra.
                        Sem cobrança confirmada nesta janela; assim que houver, mostramos o valor real.
                    </p>
                @else
                    <p class="est">
                        Receita bruta = soma das cobranças confirmadas (Asaas/PIX) na janela. Valor real.
                    </p>
                @endif
            </div>
        @endforeach

        {{-- ── Contadores ───────────────────────────────────────────────────── --}}
        <div class="card">
            <h2>Contadores</h2>
            <div class="grid">
                <div class="metric">
                    <div class="label">Membros ativos (7d)</div>
                    <div class="value gold">{{ $tok($counters['active_members']) }}</div>
                </div>
                <div class="metric">
                    <div class="label">Performers ativas (7d)</div>
                    <div class="value gold">{{ $tok($counters['active_performers']) }}</div>
                </div>
                <div class="metric">
                    <div class="label">Lives hoje</div>
                    <div class="value">{{ $tok($counters['lives_today']) }}</div>
                </div>
                <div class="metric">
                    <div class="label">Chamadas hoje</div>
                    <div class="value">{{ $tok($counters['calls_today']) }}</div>
                </div>
            </div>
        </div>

        {{-- ── Payouts em needs_review ──────────────────────────────────────── --}}
        <div class="card">
            <h2>Payouts pendentes de revisão</h2>
            @if ($pendingPayouts->isEmpty())
                <p class="empty">Nenhum payout em revisão.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Performer</th>
                            <th class="num">Tokens</th>
                            <th class="num">Valor (R$)</th>
                            <th>Período</th>
                            <th>Solicitado</th>
                        </tr>
                    </thead>
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
            @endif
        </div>

        {{-- ── Performers com strikes de no-show (agendamento de chamada) ─────── --}}
        <div class="card">
            <h2>Performers em revisão por no-show</h2>
            @if ($strikeReviewPerformers->isEmpty())
                <p class="empty">Nenhuma performer no limiar de strikes.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Performer</th>
                            <th class="num">Strikes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($strikeReviewPerformers as $row)
                            <tr>
                                <td>{{ $row['id'] }}</td>
                                <td>{{ $row['performer'] }}</td>
                                <td class="num">{{ $row['strikes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</body>
</html>
