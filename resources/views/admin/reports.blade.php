<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Denúncias — Limen</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #0a0a0a; color: #F5F0E8; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 40px 20px 64px; }
        h1 { font-weight: 600; font-size: 22px; margin: 0 0 4px; }
        .sub { color: #9a938a; font-size: 13px; margin: 0 0 28px; }
        .muted { color: #9a938a; }
        .card { border: 1px solid #262626; border-radius: 12px; padding: 20px; background: #0d0d0d; margin-top: 24px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; font-size: 14px; border-bottom: 1px solid #1c1c1c; vertical-align: top; }
        th { color: #9a938a; font-weight: 500; font-size: 12px; text-transform: uppercase; }
        .empty { color: #6f6a62; font-size: 14px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
        .tabs a { text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 8px; border: 1px solid #262626; color: #9a938a; }
        .tabs a.on { border-color: #C9A84C; color: #C9A84C; }
        .tag { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid #3a3a3a; color: #9a938a; }
        .tag.urgent { border-color: #b3402f; color: #e5705e; }
        .tag.pending { border-color: #C9A84C; color: #C9A84C; }
        .details { margin-top: 6px; font-size: 13px; color: #cfc8bd; white-space: pre-line; max-width: 380px; }
        form.act { display: inline; }
        button { font: inherit; font-size: 12px; padding: 5px 10px; margin-right: 4px; border-radius: 7px; border: 1px solid #262626; background: #141414; color: #cfc8bd; cursor: pointer; }
        button:hover { border-color: #C9A84C; color: #C9A84C; }
        .flash { border: 1px solid #C9A84C; color: #C9A84C; border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-bottom: 20px; }
        .pager { margin-top: 18px; font-size: 13px; }
        .pager a { color: #C9A84C; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Denúncias</h1>
        <p class="sub">
            Painel de moderação · {{ $pendingCount }} pendente{{ $pendingCount === 1 ? '' : 's' }}.
            O denunciante aparece pseudonimizado — o id real fica na tabela.
        </p>

        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif

        <div class="tabs">
            @foreach (['pending' => 'Pendentes', 'reviewed' => 'Revisadas', 'resolved' => 'Resolvidas', 'dismissed' => 'Descartadas', 'all' => 'Todas'] as $key => $label)
                <a href="{{ route('admin.reports', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="card">
            @if ($reports->isEmpty())
                <p class="empty">Nenhuma denúncia neste filtro.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Recebida</th>
                            <th>Denunciante</th>
                            <th>Alvo</th>
                            <th>Motivo</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reports as $report)
                            @php
                                $urgent = in_array($report['reason'], ['underage_content', 'non_consensual', 'coercion'], true);
                            @endphp
                            <tr>
                                <td>{{ $report['id'] }}</td>
                                <td class="muted">{{ optional($report['created_at'])->format('d/m/Y H:i') }}</td>
                                <td class="muted">{{ $report['reporter'] }}</td>
                                <td>{{ $report['target_type'] }} #{{ $report['target_id'] }}</td>
                                <td>
                                    <span class="tag {{ $urgent ? 'urgent' : '' }}">{{ $report['reason'] }}</span>
                                    @if ($report['details'])
                                        <div class="details">{{ $report['details'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="tag {{ $report['status'] === 'pending' ? 'pending' : '' }}">{{ $report['status'] }}</span>
                                    @if ($report['reviewed_at'])
                                        <div class="muted" style="font-size:11px;margin-top:4px">
                                            {{ optional($report['reviewed_at'])->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @foreach (['reviewed' => 'Revisar', 'resolved' => 'Resolver', 'dismissed' => 'Descartar'] as $value => $label)
                                        <form class="act" method="POST" action="{{ route('admin.reports.update', $report['id']) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ $value }}">
                                            <button type="submit">{{ $label }}</button>
                                        </form>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="pager">{{ $reports->links() }}</div>
            @endif
        </div>
    </div>
</body>
</html>
