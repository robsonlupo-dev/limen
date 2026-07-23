<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · KYC — Limen</title>
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
        .tag.pending { border-color: #C9A84C; color: #C9A84C; }
        .tag.review { border-color: #4a7fb5; color: #7fb0e0; }
        .tag.approved { border-color: #3d7a55; color: #6fcf97; }
        .tag.rejected { border-color: #b3402f; color: #e5705e; }
        .tag.ip { border-color: #b3402f; color: #e5705e; margin-top: 4px; }
        .tag.danger { border-color: #b3402f; background: #b3402f; color: #fff; font-weight: 600; margin-top: 4px; }
        form.act { display: inline; }
        button { font: inherit; font-size: 12px; padding: 5px 10px; margin-right: 4px; border-radius: 7px; border: 1px solid #262626; background: #141414; color: #cfc8bd; cursor: pointer; }
        button:hover { border-color: #C9A84C; color: #C9A84C; }
        button.danger:hover { border-color: #b3402f; color: #e5705e; }
        .flash { border: 1px solid #C9A84C; color: #C9A84C; border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-bottom: 20px; }
        .flash.info { border-color: #4a7fb5; color: #7fb0e0; }
        .flash.error { border-color: #b3402f; color: #e5705e; }
        .pager { margin-top: 18px; font-size: 13px; }
        .pager a { color: #C9A84C; }
        details.reject { margin-top: 6px; }
        details.reject summary { cursor: pointer; font-size: 12px; color: #9a938a; list-style: none; }
        details.reject summary:hover { color: #e5705e; }
        details.reject textarea { display: block; width: 100%; max-width: 320px; margin: 8px 0 6px; padding: 8px; font: inherit; font-size: 13px; background: #141414; color: #F5F0E8; border: 1px solid #262626; border-radius: 8px; resize: vertical; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Verificações KYC</h1>
        <p class="sub">
            Fila de aprovação · {{ $queueCount }} aguardando decisão.
            O documento em si (número, nome legal, arquivos) não aparece aqui — confira no painel do provider.
        </p>

        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif
        @if (session('info'))
            <div class="flash info">{{ session('info') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            @foreach (['queue' => 'Fila', 'pending' => 'Pendentes', 'review' => 'Em análise', 'approved' => 'Aprovadas', 'rejected' => 'Rejeitadas'] as $key => $label)
                <a href="{{ route('admin.kyc.panel', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="card">
            @if ($verifications->isEmpty())
                <p class="empty">Nenhuma verificação neste filtro.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Performer</th>
                            <th>Status</th>
                            <th>Enviado em</th>
                            <th>Revisado por</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($verifications as $v)
                            <tr>
                                <td>{{ $v['id'] }}</td>
                                <td>
                                    {{ $v['user']['stage_name'] ?? $v['user']['name'] ?? '—' }}
                                    <div class="muted" style="font-size:12px">{{ $v['user']['email'] }}</div>
                                    @if ($v['shared_ip_others'] > 0)
                                        {{-- Sinal fraco sozinho (NAT, Wi-Fi compartilhado): sinaliza, nunca decide. --}}
                                        <span class="tag ip">IP de cadastro compartilhado com {{ $v['shared_ip_others'] }} outra{{ $v['shared_ip_others'] > 1 ? 's' : '' }}</span>
                                    @endif
                                    @if ($v['blacklist_hit'])
                                        {{-- CPF/documento já associado a uma conta banida. Sinal, não veredito. --}}
                                        <span class="tag danger">⚠️ CPF banido anteriormente</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="tag {{ $v['status'] }}">{{ $v['status'] }}</span>
                                    @if ($v['provider_status'] && $v['provider_status'] !== $v['status'])
                                        <div class="muted" style="font-size:11px;margin-top:4px">provider: {{ $v['provider_status'] }}</div>
                                    @endif
                                </td>
                                <td class="muted">{{ optional($v['created_at'])->format('d/m/Y H:i') }}</td>
                                <td class="muted">
                                    {{ $v['reviewer']['name'] ?? '—' }}
                                    @if ($v['reviewed_at'])
                                        <div style="font-size:11px;margin-top:4px">{{ optional($v['reviewed_at'])->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($v['actionable'])
                                        <form class="act" method="POST" action="{{ route('admin.kyc.panel.approve', $v['id']) }}"
                                              onsubmit="return confirm('Aprovar a verificação #{{ $v['id'] }}? A performer entra no catálogo.')">
                                            @csrf
                                            <button type="submit">Aprovar</button>
                                        </form>
                                        <details class="reject">
                                            <summary>Rejeitar…</summary>
                                            <form method="POST" action="{{ route('admin.kyc.panel.reject', $v['id']) }}">
                                                @csrf
                                                <textarea name="reason" rows="3" maxlength="500" required
                                                          placeholder="Motivo da rejeição (vai no e-mail para a performer)"></textarea>
                                                <button type="submit" class="danger">Confirmar rejeição</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="muted" style="font-size:12px">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="pager">{{ $verifications->links() }}</div>
            @endif
        </div>
    </div>
</body>
</html>
