<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Waitlist — Limen</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #0a0a0a; color: #F5F0E8; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 40px 20px 64px; }
        h1 { font-weight: 600; font-size: 22px; margin: 0 0 4px; }
        .sub { color: #9a938a; font-size: 13px; margin: 0 0 28px; }
        .gold { color: #C9A84C; }
        .muted { color: #9a938a; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
        .stat { border: 1px solid #262626; border-radius: 12px; padding: 18px; background: #0d0d0d; }
        .stat .n { font-size: 30px; font-weight: 700; color: #C9A84C; }
        .stat .l { font-size: 12px; color: #9a938a; text-transform: uppercase; letter-spacing: 1px; margin-top: 4px; }
        .card { border: 1px solid #262626; border-radius: 12px; padding: 20px; background: #0d0d0d; margin-top: 24px; }
        .card h2 { font-size: 15px; font-weight: 600; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 9px 8px; font-size: 14px; border-bottom: 1px solid #1c1c1c; }
        th { color: #9a938a; font-weight: 500; font-size: 12px; text-transform: uppercase; }
        td.num, th.num { text-align: right; }
        .chart { display: flex; align-items: flex-end; gap: 6px; height: 120px; }
        .chart .col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 6px; }
        .chart .col > .b { width: 100%; background: #C9A84C; border-radius: 4px 4px 0 0; min-height: 2px; }
        .chart .col > .d { font-size: 9px; color: #6f6a62; }
        .empty { color: #6f6a62; font-size: 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Waitlist — Founding Members</h1>
        <p class="sub">Painel administrativo · dados agregados</p>

        <div class="grid">
            <div class="stat"><div class="n">{{ number_format($summary['total'], 0, ',', '.') }}</div><div class="l">Inscritos</div></div>
            <div class="stat"><div class="n">{{ number_format($summary['confirmed'], 0, ',', '.') }}</div><div class="l">Confirmados</div></div>
            <div class="stat"><div class="n">{{ $summary['confirmation_rate'] }}%</div><div class="l">Taxa de confirmação</div></div>
            <div class="stat"><div class="n">{{ $summary['viral_coefficient'] }}</div><div class="l">Coeficiente viral (K)</div></div>
            <div class="stat"><div class="n">{{ number_format($summary['by_role']['member'], 0, ',', '.') }}</div><div class="l">Membros</div></div>
            <div class="stat"><div class="n">{{ number_format($summary['by_role']['performer'], 0, ',', '.') }}</div><div class="l">Performers</div></div>
        </div>

        {{-- Daily growth --}}
        <div class="card">
            <h2>Crescimento diário (últimos 14 dias)</h2>
            @php $max = max(1, $summary['daily_growth']->max('count')); @endphp
            <div class="chart">
                @foreach ($summary['daily_growth'] as $day)
                    <div class="col" title="{{ $day['date'] }}: {{ $day['count'] }}">
                        <div class="b" style="height: {{ (int) round($day['count'] / $max * 100) }}%;"></div>
                        <div class="d">{{ \Illuminate\Support\Str::substr($day['date'], 8, 2) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Top referrers --}}
        <div class="card">
            <h2>Top 10 indicadores</h2>
            @if ($summary['top_referrers']->isEmpty())
                <p class="empty">Nenhuma indicação confirmada ainda.</p>
            @else
                <table>
                    <thead>
                        <tr><th>#</th><th>Nome</th><th>Código</th><th>Nível</th><th class="num">Indicações</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['top_referrers'] as $i => $r)
                            <tr>
                                <td class="muted">{{ $i + 1 }}</td>
                                <td>{{ $r['name'] }}</td>
                                <td class="muted">{{ $r['invite_code'] }}</td>
                                <td class="gold">{{ $r['tier'] }}</td>
                                <td class="num">{{ $r['referral_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</body>
</html>
