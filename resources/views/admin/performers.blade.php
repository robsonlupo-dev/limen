<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Performers — Limen</title>
    <style>
        /* Fontes self-hosted (public/fonts). Área logada NÃO fala com terceiro —
           mesma regra do dashboard (ExternalAssetPolicyTest). Blade standalone,
           então as três famílias são declaradas aqui. Ver docs/PIXEL_AUDIT.md. */
        @font-face {
            font-family: 'Cormorant Garamond';
            font-style: normal; font-weight: 300 700; font-display: swap;
            src: url('/fonts/cormorant-garamond-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Cormorant Garamond';
            font-style: normal; font-weight: 300 700; font-display: swap;
            src: url('/fonts/cormorant-garamond-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Manrope'; font-style: normal; font-weight: 200 800;
            font-display: swap; src: url('/fonts/manrope-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Manrope'; font-style: normal; font-weight: 200 800;
            font-display: swap; src: url('/fonts/manrope-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'JetBrains Mono'; font-style: normal; font-weight: 100 800;
            font-display: swap; src: url('/fonts/jetbrains-mono-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'JetBrains Mono'; font-style: normal; font-weight: 100 800;
            font-display: swap; src: url('/fonts/jetbrains-mono-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        :root {
            color-scheme: dark;
            --bg: #12100D; --surface: #1A1511; --surface-2: #241D16;
            --ink: #F2EBE1; --ink-soft: #C9BEB0; --ink-mute: #8C8073; --ink-dim: #A2968A;
            --gold: #D9B872; --gold-light: #E8C87D; --gold-bg: #3A2A1C; --gold-border: #55411F;
            --line: #34291E; --line-2: #2B2219;
            --green: #9FD0AC; --green-bg: #1F2C22; --green-border: #38553F;
            --red: #F4C1B9; --red-dot: #C0564B; --red-bg: #2A1E1B; --red-border: #6A3A31;
            --teal: #5F7C88; --teal-light: #96C3D2; --teal-bg: #1C2830; --teal-border: #33505E;
            --warn: #E0A85C; --warn-light: #F0D8A8; --warn-bg: #26201A; --warn-border: #5A4526;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--ink);
               font-family: "Manrope", system-ui, -apple-system, sans-serif;
               line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--gold); text-decoration: none; }
        a:hover { color: #F0D49A; }

        .shell { display: flex; flex-direction: column; min-height: 100vh; }

        .topbar { height: 60px; flex-shrink: 0; padding: 0 24px; display: flex;
                   align-items: center; gap: 16px; background: var(--surface);
                   border-bottom: 1px solid var(--line); }
        .topbar .logo { display: flex; align-items: center; gap: 10px; }
        .topbar .logo-text { font-family: "Cormorant Garamond", Georgia, serif;
                             font-size: 21px; font-weight: 600; letter-spacing: 0.2em; color: var(--ink); }
        .topbar .divider { width: 1px; height: 24px; background: var(--line); }
        .topbar .admin-badge { padding: 4px 11px; border-radius: 999px; background: #33261A;
                               border: 1px solid var(--gold-border); color: var(--gold-light);
                               font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .topbar .env-badge { padding: 3px 9px; border-radius: 6px; background: var(--teal-bg);
                             border: 1px solid var(--teal-border); color: var(--teal-light);
                             font-size: 10px; font-weight: 700; letter-spacing: 0.1em; }
        .topbar .spacer { flex-grow: 1; }

        .body-row { flex-grow: 1; display: flex; min-height: 0; }

        .sidebar { width: 240px; flex-shrink: 0; padding: 20px 14px; background: #171310;
                   border-right: 1px solid var(--line); display: flex; flex-direction: column;
                   gap: 18px; overflow-y: auto; }
        .sidebar .group-label { padding: 0 10px 3px; font-size: 10px; font-weight: 700;
                                letter-spacing: 0.15em; text-transform: uppercase; color: var(--ink-mute); }
        .sidebar .nav-group { display: flex; flex-direction: column; gap: 3px; }
        .sidebar .nav-item { display: flex; align-items: center; gap: 9px; min-height: 36px;
                             padding: 7px 10px; border-radius: 8px; color: #BDB2A3; font-size: 13px;
                             font-weight: 500; text-decoration: none; transition: background .12s; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.03); color: var(--ink); }
        .sidebar .nav-item.active { background: #2A2017; color: var(--gold-light); font-weight: 600; }
        .sidebar .nav-item .dot { width: 5px; height: 5px; border-radius: 999px; background: var(--gold); flex-shrink: 0; }
        .sidebar .nav-sub { padding-left: 24px; }

        .main { flex-grow: 1; padding: 24px 28px; display: flex; flex-direction: column;
                gap: 18px; min-width: 0; overflow-y: auto; }

        .title-row h1 { font-family: "Cormorant Garamond", Georgia, serif; font-size: 28px;
                        font-weight: 600; letter-spacing: 0.01em; color: #F6F1E8; }
        .title-row .sub { font-size: 12px; color: var(--ink-dim); margin-top: 3px; }

        .flash { border-radius: 11px; padding: 11px 15px; font-size: 13px; font-weight: 600; }
        .flash-ok { background: var(--green-bg); border: 1px solid var(--green-border); color: var(--green); }
        .flash-info { background: var(--teal-bg); border: 1px solid var(--teal-border); color: var(--teal-light); }
        .flash-err { background: var(--red-bg); border: 1px solid var(--red-border); color: var(--red); }

        /* ── Filtros ───────────────────────────────────────────────── */
        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .tabs { display: flex; flex-wrap: wrap; gap: 6px; }
        .tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; min-height: 34px;
               border-radius: 999px; border: 1px solid var(--line); color: var(--ink-mute);
               font-size: 12.5px; font-weight: 600; }
        .tab:hover { border-color: var(--gold-border); color: var(--ink); }
        .tab.on { border-color: var(--gold); color: var(--gold-light); background: var(--gold-bg); }
        .tab .cnt { font-family: "JetBrains Mono", monospace; font-size: 10.5px; opacity: 0.85; }
        .search { margin-left: auto; display: flex; gap: 6px; }
        .search input { min-height: 34px; padding: 6px 12px; border-radius: 9px; border: 1px solid var(--line);
                        background: var(--surface-2); color: var(--ink); font: inherit; font-size: 13px; min-width: 180px; }
        .search input:focus { outline: none; border-color: var(--gold); }
        .search button { min-height: 34px; padding: 6px 14px; border-radius: 9px; border: 1px solid var(--gold-border);
                         background: var(--gold-bg); color: var(--gold-light); font: inherit; font-size: 13px;
                         font-weight: 600; cursor: pointer; }
        .search button:hover { border-color: var(--gold); }
        .verified-links { display: flex; gap: 6px; font-size: 12px; }

        /* ── Tabela ────────────────────────────────────────────────── */
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 13px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 11px 14px; font-size: 12.5px; border-bottom: 1px solid var(--line-2); vertical-align: middle; }
        th { color: var(--ink-mute); font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; white-space: nowrap; }
        td.num, th.num { text-align: right; font-family: "JetBrains Mono", monospace; }
        .name { font-weight: 600; color: var(--ink); }
        .id { font-family: "JetBrains Mono", monospace; font-size: 11px; color: var(--ink-mute); }
        .empty { color: var(--ink-mute); font-size: 13px; padding: 22px 14px; }

        .tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px;
               border: 1px solid var(--line); color: var(--ink-mute); letter-spacing: 0.02em; }
        .tag.st-active { border-color: var(--green-border); color: var(--green); background: var(--green-bg); }
        .tag.st-pending { border-color: var(--warn-border); color: var(--warn-light); background: var(--warn-bg); }
        .tag.st-suspended { border-color: var(--warn-border); color: var(--warn); background: var(--warn-bg); }
        .tag.st-banned { border-color: var(--red-border); color: var(--red); background: var(--red-bg); }
        .tag.live { border-color: var(--red-border); color: #F0A99E; background: #2A1B18; }
        .tag.verified { border-color: var(--gold-border); color: var(--gold-light); background: var(--gold-bg); }
        .tag.tier { border-color: var(--teal-border); color: var(--teal-light); background: var(--teal-bg); text-transform: capitalize; }
        .strike-hot { color: var(--red); font-weight: 700; }

        /* ── Ações ─────────────────────────────────────────────────── */
        .actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        select, .actions button { font: inherit; font-size: 12px; min-height: 32px; padding: 4px 9px;
                 border-radius: 8px; border: 1px solid var(--line); background: var(--surface-2);
                 color: var(--ink-soft); cursor: pointer; }
        .actions form { display: flex; gap: 5px; align-items: center; }
        .actions button:hover { border-color: var(--gold); color: var(--gold-light); }
        details.ban { position: relative; }
        details.ban > summary { list-style: none; cursor: pointer; font-size: 12px; min-height: 32px;
                                display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 8px;
                                border: 1px solid var(--line); color: var(--ink-mute); }
        details.ban > summary::-webkit-details-marker { display: none; }
        details.ban > summary:hover { border-color: var(--red-border); color: var(--red); }
        details.ban .ban-body { margin-top: 8px; padding: 12px; background: var(--surface-2);
                                border: 1px solid var(--red-border); border-radius: 10px; width: 260px; }
        details.ban textarea { display: block; width: 100%; margin: 0 0 8px; padding: 8px; font: inherit;
                               font-size: 12px; background: var(--bg); color: var(--ink); border: 1px solid var(--line);
                               border-radius: 8px; resize: vertical; min-height: 56px; }
        details.ban button.confirm { border-color: var(--red-border); color: var(--red); background: var(--red-bg);
                                     font-weight: 600; width: 100%; }
        details.ban button.confirm:hover { border-color: var(--red-dot); }

        .pager { display: flex; gap: 12px; align-items: center; font-size: 13px; padding: 4px 2px; }

        @media (max-width: 800px) {
            .sidebar { display: none; }
            .topbar { padding: 0 16px; }
            .main { padding: 20px 16px; }
            .search { margin-left: 0; width: 100%; }
            .search input { flex-grow: 1; min-width: 0; }
        }
    </style>
</head>
<body>
@php
    $tok = fn ($n) => number_format((int) $n, 0, ',', '.');
    $statusLabels = ['active' => 'Ativas', 'pending' => 'Pendentes', 'suspended' => 'Suspensas', 'banned' => 'Banidas'];
    $worldLabels = ['mulheres' => 'Mulheres', 'homens' => 'Homens', 'casais' => 'Casais', 'trans' => 'Trans'];
@endphp

<div class="shell">

    {{-- HEADER --}}
    <header class="topbar">
        <div class="logo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D9B872" stroke-width="1.6" aria-hidden="true">
                <path d="M4 21V11a8 8 0 0 1 16 0v10"></path>
            </svg>
            <span class="logo-text">LIMEN</span>
        </div>
        <div class="divider"></div>
        <span class="admin-badge">Painel Admin</span>
        @if (app()->environment('staging', 'local'))
            <span class="env-badge">{{ strtoupper(app()->environment()) }}</span>
        @endif
        <div class="spacer"></div>
    </header>

    <div class="body-row">

        {{-- SIDEBAR --}}
        <nav class="sidebar" aria-label="Navegação do admin">
            <div class="nav-group">
                <span class="group-label">Visão geral</span>
                <a href="{{ route('admin.dashboard') }}" class="nav-item">
                    <span class="dot"></span>Painel de Receita
                </a>
            </div>
            <div class="nav-group">
                <span class="group-label">Dinheiro</span>
                <a href="{{ route('admin.dashboard') }}#payouts" class="nav-item nav-sub">Payouts</a>
                <a href="{{ route('admin.dashboard') }}#ledger" class="nav-item nav-sub">Ledger &amp; extratos</a>
            </div>
            <div class="nav-group">
                <span class="group-label">Pessoas</span>
                <a href="{{ route('admin.performers') }}" class="nav-item nav-sub active">Performers</a>
                <a href="{{ route('admin.kyc.panel') }}" class="nav-item nav-sub">Fila de KYC</a>
                <a href="{{ route('admin.waitlist') }}" class="nav-item nav-sub">Waitlist</a>
            </div>
            <div class="nav-group">
                <span class="group-label">Confiança &amp; segurança</span>
                <a href="{{ route('admin.reports') }}" class="nav-item nav-sub">Denúncias</a>
            </div>
        </nav>

        {{-- CONTEÚDO --}}
        <main class="main">
            <div class="title-row">
                <h1>Performers</h1>
                <p class="sub">Gestão de performers — status, tier, verificação e strikes. {{ $tok($totalAll) }} no total. Sem dados de membro.</p>
            </div>

            @if (session('success'))
                <div class="flash flash-ok">{{ session('success') }}</div>
            @endif
            @if (session('info'))
                <div class="flash flash-info">{{ session('info') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash flash-err">{{ $errors->first() }}</div>
            @endif

            {{-- Filtros --}}
            <div class="filters">
                <div class="tabs">
                    <a href="{{ route('admin.performers', array_filter(['verified' => $verified, 'q' => $term ?: null])) }}"
                       class="tab {{ $status === 'all' ? 'on' : '' }}">
                        Todas <span class="cnt">{{ $tok($totalAll) }}</span>
                    </a>
                    @foreach ($statusLabels as $key => $label)
                        <a href="{{ route('admin.performers', array_filter(['status' => $key, 'verified' => $verified, 'q' => $term ?: null])) }}"
                           class="tab {{ $status === $key ? 'on' : '' }}">
                            {{ $label }} <span class="cnt">{{ $tok($counts[$key] ?? 0) }}</span>
                        </a>
                    @endforeach
                </div>
                <form class="search" method="GET" action="{{ route('admin.performers') }}">
                    @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
                    @if ($verified)<input type="hidden" name="verified" value="{{ $verified }}">@endif
                    <input type="search" name="q" value="{{ $term }}" placeholder="Buscar por stage name…" aria-label="Buscar por stage name">
                    <button type="submit">Buscar</button>
                </form>
            </div>

            <div class="verified-links">
                <span style="color: var(--ink-mute);">Verificação:</span>
                <a href="{{ route('admin.performers', array_filter(['status' => $status !== 'all' ? $status : null, 'q' => $term ?: null])) }}"
                   style="{{ $verified === null ? 'color: var(--gold-light); font-weight: 700;' : '' }}">todas</a>
                <a href="{{ route('admin.performers', array_filter(['status' => $status !== 'all' ? $status : null, 'q' => $term ?: null, 'verified' => 'yes'])) }}"
                   style="{{ $verified === 'yes' ? 'color: var(--gold-light); font-weight: 700;' : '' }}">verificadas</a>
                <a href="{{ route('admin.performers', array_filter(['status' => $status !== 'all' ? $status : null, 'q' => $term ?: null, 'verified' => 'no'])) }}"
                   style="{{ $verified === 'no' ? 'color: var(--gold-light); font-weight: 700;' : '' }}">não verificadas</a>
            </div>

            {{-- Tabela --}}
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Performer</th>
                                <th>Mundo</th>
                                <th>Status</th>
                                <th>Verificação</th>
                                <th>Tier</th>
                                <th class="num">Strikes</th>
                                <th>Cadastro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($performers as $p)
                                <tr>
                                    <td>
                                        <div class="name">{{ $p['stage_name'] }}</div>
                                        <div class="id">#{{ $p['id'] }}@unless ($p['has_profile']) · sem perfil @endunless</div>
                                    </td>
                                    <td>{{ $worldLabels[$p['world']] ?? ($p['world'] ?? '—') }}</td>
                                    <td>
                                        <span class="tag st-{{ $p['status'] }}">{{ $statusLabels[$p['status']] ?? $p['status'] }}</span>
                                        @if ($p['is_live'])<span class="tag live">Ao vivo</span>@endif
                                    </td>
                                    <td>
                                        @if ($p['is_verified'])
                                            <span class="tag verified">Verificada</span>
                                        @else
                                            <span class="tag">—</span>
                                        @endif
                                    </td>
                                    <td>@if ($p['tier'])<span class="tag tier">{{ $p['tier'] }}</span>@else <span class="tag">—</span>@endif</td>
                                    <td class="num {{ $p['strikes'] >= 3 ? 'strike-hot' : '' }}">{{ $p['strikes'] }}</td>
                                    <td>{{ optional($p['created_at'])->format('d/m/Y') }}</td>
                                    <td>
                                        <div class="actions">
                                            {{-- Conceder tier (endpoint já auditado) --}}
                                            @if ($p['has_profile'])
                                                <form method="POST" action="{{ route('admin.performers.tier.store', $p['profile_id']) }}">
                                                    @csrf
                                                    <select name="tier" aria-label="Tier de {{ $p['stage_name'] }}">
                                                        @foreach ($tiers as $t)
                                                            <option value="{{ $t }}" {{ $p['tier'] === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit">Aplicar</button>
                                                </form>
                                            @endif

                                            {{-- Banir (endpoint já auditado; some para já banida) --}}
                                            @if ($p['status'] !== 'banned')
                                                <details class="ban">
                                                    <summary>Banir</summary>
                                                    <div class="ban-body">
                                                        <form method="POST" action="{{ route('admin.users.ban', $p['id']) }}">
                                                            @csrf
                                                            <textarea name="reason" required maxlength="500" placeholder="Motivo do ban (obrigatório, vai no audit)"></textarea>
                                                            <button type="submit" class="confirm">Confirmar ban permanente</button>
                                                        </form>
                                                    </div>
                                                </details>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="empty">Nenhuma performer encontrada com esses filtros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($performers->hasPages())
                <div class="pager">{{ $performers->links() }}</div>
            @endif
        </main>
    </div>
</div>
</body>
</html>
