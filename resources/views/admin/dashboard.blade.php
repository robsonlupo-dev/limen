<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Painel — Limen</title>
    <style>
        /* Fontes self-hosted (public/fonts). Área logada NÃO fala com terceiro:
           nada de Google Fonts no <head> — o request levaria IP e User-Agent do
           admin. Esta Blade é standalone (não carrega o bundle/fonts.css), então
           as três famílias são declaradas aqui. Variáveis: um arquivo por
           família+subset cobre a faixa de peso inteira. Ver docs/PIXEL_AUDIT.md. */
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
            --bg: #12100D;
            --surface: #1A1511;
            --surface-2: #241D16;
            --ink: #F2EBE1;
            --ink-soft: #C9BEB0;
            --ink-mute: #8C8073;
            --ink-dim: #A2968A;
            --gold: #D9B872;
            --gold-light: #E8C87D;
            --gold-bg: #3A2A1C;
            --gold-border: #55411F;
            --line: #34291E;
            --line-2: #2B2219;
            --green: #9FD0AC;
            --green-dark: #7FB68C;
            --green-bg: #1F2C22;
            --green-border: #38553F;
            --red: #F4C1B9;
            --red-dot: #C0564B;
            --red-bg: #2A1E1B;
            --red-border: #6A3A31;
            --teal: #5F7C88;
            --teal-light: #96C3D2;
            --teal-bg: #1C2830;
            --teal-border: #33505E;
            --warn: #E0A85C;
            --warn-light: #F0D8A8;
            --warn-bg: #26201A;
            --warn-border: #5A4526;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--ink);
               font-family: "Manrope", system-ui, -apple-system, sans-serif;
               line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--gold); text-decoration: none; }
        a:hover { color: #F0D49A; }
 
        /* ── Layout ────────────────────────────────────────────────── */
        .shell { display: flex; flex-direction: column; min-height: 100vh; }
 
        .topbar { height: 60px; flex-shrink: 0; padding: 0 24px; display: flex;
                   align-items: center; gap: 16px; background: var(--surface);
                   border-bottom: 1px solid var(--line); }
        .topbar .logo { display: flex; align-items: center; gap: 10px; }
        .topbar .logo-text { font-family: "Cormorant Garamond", Georgia, serif;
                             font-size: 21px; font-weight: 600; letter-spacing: 0.2em;
                             color: var(--ink); }
        .topbar .divider { width: 1px; height: 24px; background: var(--line); }
        .topbar .admin-badge { padding: 4px 11px; border-radius: 999px;
                               background: #33261A; border: 1px solid var(--gold-border);
                               color: var(--gold-light); font-size: 11px; font-weight: 700;
                               letter-spacing: 0.08em; text-transform: uppercase; }
        .topbar .env-badge { padding: 3px 9px; border-radius: 6px;
                             background: var(--teal-bg); border: 1px solid var(--teal-border);
                             color: var(--teal-light); font-size: 10px; font-weight: 700;
                             letter-spacing: 0.1em; }
        .topbar .spacer { flex-grow: 1; }
 
        .body-row { flex-grow: 1; display: flex; min-height: 0; }
 
        /* ── Sidebar ───────────────────────────────────────────────── */
        .sidebar { width: 240px; flex-shrink: 0; padding: 20px 14px; background: #171310;
                   border-right: 1px solid var(--line); display: flex; flex-direction: column;
                   gap: 18px; overflow-y: auto; }
        .sidebar .group-label { padding: 0 10px 3px; font-size: 10px; font-weight: 700;
                                letter-spacing: 0.15em; text-transform: uppercase;
                                color: var(--ink-mute); }
        .sidebar .nav-group { display: flex; flex-direction: column; gap: 3px; }
        .sidebar .nav-item { display: flex; align-items: center; gap: 9px;
                             min-height: 36px; padding: 7px 10px; border-radius: 8px;
                             color: #BDB2A3; font-size: 13px; font-weight: 500;
                             text-decoration: none; transition: background .12s; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.03); color: var(--ink); }
        .sidebar .nav-item.active { background: #2A2017; color: var(--gold-light);
                                    font-weight: 600; }
        .sidebar .nav-item .dot { width: 5px; height: 5px; border-radius: 999px;
                                  background: var(--gold); flex-shrink: 0; }
        .sidebar .nav-item .badge { margin-left: auto; font-family: "JetBrains Mono", monospace;
                                    font-size: 10.5px; font-weight: 600; border-radius: 999px;
                                    padding: 2px 7px; }
        .sidebar .nav-item .badge-gold { color: var(--gold-light); background: var(--gold-bg); }
        .sidebar .nav-item .badge-red { color: #F0A99E; background: #4A2420; }
        .sidebar .nav-item .badge-warn { color: #F0C9A0; background: #4A2E1C; }
        .sidebar .nav-sub { padding-left: 24px; }
 
        .sidebar .ledger-card { margin-top: auto; padding: 12px; background: #1E1A14;
                                border: 1px solid var(--line); border-radius: 10px;
                                display: flex; flex-direction: column; gap: 5px; }
        .sidebar .ledger-card .status { display: flex; align-items: center; gap: 6px;
                                        font-size: 11px; font-weight: 700; letter-spacing: 0.04em; }
        .sidebar .ledger-card .detail { font-size: 10px; line-height: 1.5; color: var(--ink-mute); }
 
        /* ── Main content ──────────────────────────────────────────── */
        .main { flex-grow: 1; padding: 24px 28px; display: flex; flex-direction: column;
                gap: 18px; min-width: 0; overflow-y: auto; }
 
        /* ── Title row ─────────────────────────────────────────────── */
        .title-row { display: flex; align-items: flex-end; gap: 14px; }
        .title-row h1 { font-family: "Cormorant Garamond", Georgia, serif; font-size: 28px;
                        font-weight: 600; letter-spacing: 0.01em; color: #F6F1E8; }
        .title-row .sub { font-size: 12px; color: var(--ink-dim); margin-top: 3px; }
 
        /* ── Alert banners ─────────────────────────────────────────── */
        .alerts { display: flex; gap: 10px; flex-wrap: wrap; }
        .alert { flex: 1; min-width: 200px; display: flex; align-items: center; gap: 10px;
                 padding: 11px 14px; border-radius: 11px; }
        .alert-warn { background: var(--warn-bg); border: 1px solid var(--warn-border); }
        .alert-red { background: var(--red-bg); border: 1px solid var(--red-border); }
        .alert-mute { background: #1E1A14; border: 1px solid var(--line); }
        .alert .title { font-size: 12.5px; font-weight: 700; }
        .alert .detail { font-size: 11px; color: var(--ink-dim); }
        .alert .link { font-size: 12px; font-weight: 700; margin-left: auto; flex-shrink: 0; }
 
        /* ── KPI cards ─────────────────────────────────────────────── */
        .kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
        .kpi { padding: 14px 15px; background: var(--surface); border: 1px solid var(--line);
               border-radius: 12px; display: flex; flex-direction: column; gap: 7px; }
        .kpi .label { font-size: 10px; font-weight: 700; letter-spacing: 0.12em;
                      text-transform: uppercase; color: var(--ink-mute); }
        .kpi .value { font-family: "JetBrains Mono", monospace; font-size: 24px;
                      font-weight: 600; letter-spacing: -0.02em; }
        .kpi .note { font-size: 11px; color: var(--ink-mute); }
 
        /* ── Cards ─────────────────────────────────────────────────── */
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 13px; }
        .card-pad { padding: 17px 19px; }
        .card h2 { margin: 0; font-size: 13.5px; font-weight: 700; color: var(--ink);
                   letter-spacing: 0.01em; }
        .card .card-sub { font-size: 11px; color: var(--ink-mute); }
 
        /* ── Chart + Vertical ──────────────────────────────────────── */
        .chart-row { display: flex; gap: 14px; align-items: stretch; }
        .chart-row > .card:first-child { flex: 1.55; }
        .chart-row > .card:last-child { flex: 1; }
 
        .chart-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
        .chart-legend { margin-left: auto; display: flex; gap: 12px; }
        .chart-legend span { display: flex; align-items: center; gap: 5px; font-size: 11px;
                             color: var(--ink-dim); }
        .chart-legend .swatch { width: 8px; height: 8px; border-radius: 2px; }
 
        .chart-bars { display: flex; align-items: flex-end; justify-content: space-between;
                      height: 160px; border-left: 1px solid var(--line-2);
                      border-bottom: 1px solid var(--line-2); padding: 0 8px 0 10px; gap: 4px; }
        .bar-pair { display: flex; align-items: flex-end; gap: 3px; height: 100%; flex: 1; justify-content: center; }
        .bar { width: 10px; border-radius: 3px 3px 0 0; min-height: 2px; transition: height .3s; }
        .bar-gold { background: var(--gold); }
        .bar-teal { background: var(--teal); }
 
        .chart-footer { display: flex; gap: 20px; padding-top: 12px; border-top: 1px solid var(--line-2);
                        margin-top: 14px; }
        .chart-stat { display: flex; flex-direction: column; gap: 2px; }
        .chart-stat .stat-label { font-size: 10px; font-weight: 700; letter-spacing: 0.1em;
                                  text-transform: uppercase; color: var(--ink-mute); }
        .chart-stat .stat-value { font-family: "JetBrains Mono", monospace; font-size: 14px;
                                  font-weight: 600; color: var(--ink); }
 
        /* ── Vertical bars ─────────────────────────────────────────── */
        .vert-list { display: flex; flex-direction: column; gap: 11px; }
        .vert-item { display: flex; flex-direction: column; gap: 5px; }
        .vert-head { display: flex; align-items: center; gap: 7px; }
        .vert-head .name { flex-grow: 1; font-size: 12px; font-weight: 600; color: var(--ink); }
        .vert-head .split-tag { font-size: 10px; font-weight: 700; border-radius: 5px;
                                padding: 2px 6px; }
        .split-green { color: var(--green); background: var(--green-bg); border: 1px solid var(--green-border); }
        .split-blue { color: var(--teal-light); background: var(--teal-bg); border: 1px solid var(--teal-border); }
        .vert-head .amount { font-family: "JetBrains Mono", monospace; font-size: 11.5px;
                             color: var(--ink-soft); width: 52px; text-align: right; }
        .vert-bar { height: 5px; background: var(--surface-2); border-radius: 999px; overflow: hidden; }
        .vert-bar .fill { height: 5px; border-radius: 999px; }
        .fill-gold { background: var(--gold); }
        .fill-teal { background: var(--teal); }
 
        .vert-note { margin-top: auto; padding-top: 11px; border-top: 1px solid var(--line-2);
                     font-size: 11px; line-height: 1.5; color: var(--ink-mute); }
 
        /* ── Receita detail cards ──────────────────────────────────── */
        .revenue-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .rev-card { padding: 16px; background: var(--surface); border: 1px solid var(--line);
                    border-radius: 12px; }
        .rev-card h3 { font-size: 13px; font-weight: 600; margin-bottom: 12px;
                       display: flex; align-items: center; gap: 8px; }
        .rev-card .est-badge { font-size: 10px; padding: 1px 6px; border-radius: 999px;
                               border: 1px solid var(--ink-mute); color: var(--ink-mute); }
        .rev-metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .rev-metric { padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--line);
                      border-radius: 8px; }
        .rev-metric .label { font-size: 10px; color: var(--ink-mute); text-transform: uppercase;
                             letter-spacing: 0.05em; margin-bottom: 4px; }
        .rev-metric .value { font-family: "JetBrains Mono", monospace; font-size: 18px;
                             font-weight: 600; font-variant-numeric: tabular-nums; }
        .rev-metric .unit { font-size: 10px; color: var(--ink-mute); font-weight: 400; margin-left: 2px; }
        .spend-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
        .spend-metric { padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--line);
                        border-radius: 8px; }
        .spend-metric .label { font-size: 10px; color: var(--ink-mute); text-transform: uppercase;
                               letter-spacing: 0.04em; margin-bottom: 3px; }
        .spend-metric .value { font-family: "JetBrains Mono", monospace; font-size: 14px;
                               font-weight: 600; }
        .est-note { color: var(--ink-mute); font-size: 11px; margin-top: 8px; }
 
        /* ── Tables ────────────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; font-size: 12.5px;
                 border-bottom: 1px solid var(--line-2); }
        th { color: var(--ink-mute); font-weight: 700; font-size: 10px;
             text-transform: uppercase; letter-spacing: 0.1em; }
        td.num, th.num { text-align: right; font-family: "JetBrains Mono", monospace; }
        .empty { color: var(--ink-mute); font-size: 12.5px; padding: 8px 0; }
 
        /* ── Health row ────────────────────────────────────────────── */
        .health-row { display: flex; gap: 14px; align-items: stretch; }
        .health-row > .card { flex: 1; }
        .health-item { display: flex; align-items: center; gap: 9px; padding: 3px 0; }
        .health-item .dot { width: 6px; height: 6px; border-radius: 999px; flex-shrink: 0; }
        .dot-ok { background: var(--green-dark); }
        .dot-warn { background: var(--warn); }
        .dot-err { background: var(--red-dot); }
        .health-item .name { flex-grow: 1; font-size: 12px; color: #E4DBCD; }
        .health-item .info { font-family: "JetBrains Mono", monospace; font-size: 11px;
                             color: var(--ink-mute); }
 
        /* ── Payout table card ─────────────────────────────────────── */
        .payout-header { display: flex; align-items: center; gap: 12px; padding: 15px 19px;
                         border-bottom: 1px solid var(--line-2); }
        .payout-header .count { font-family: "JetBrains Mono", monospace; font-size: 11px;
                                font-weight: 600; color: var(--gold-light); background: var(--gold-bg);
                                border-radius: 999px; padding: 2px 8px; }
 
        /* ── Pill & status ─────────────────────────────────────────── */
        .pill { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
                border: 1px solid var(--gold); color: var(--gold); }
        .status-ok { color: var(--green); }
        .status-warn { color: var(--red); }
        .color-gold { color: var(--gold); }
        .color-green { color: var(--green); }
        .color-red { color: var(--red); }
        .color-blue { color: var(--teal-light); }
 
        /* ── Bottom branding ───────────────────────────────────────── */
        .bottom-bar { padding: 20px 28px; text-align: center; border-top: 1px solid var(--line); }
        .bottom-logo { font-family: "Cormorant Garamond", Georgia, serif; font-size: 18px;
                       font-weight: 600; letter-spacing: 0.25em; color: var(--ink-mute); }
 
        /* ── Responsive ────────────────────────────────────────────── */
        @media (max-width: 1100px) {
            .kpis { grid-template-columns: repeat(3, 1fr); }
            .chart-row { flex-direction: column; }
            .health-row { flex-direction: column; }
        }
        @media (max-width: 800px) {
            .sidebar { display: none; }
            .kpis { grid-template-columns: repeat(2, 1fr); }
            .revenue-grid { grid-template-columns: 1fr; }
            .alerts { flex-direction: column; }
            .topbar { padding: 0 16px; }
            .main { padding: 20px 16px; }
        }
        @media (max-width: 500px) {
            .kpis { grid-template-columns: 1fr; }
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
    $r30 = $revenue['last30'];
    $maxSpend = max(1, max($r30['spent_by_type'] ?? [1]));
@endphp
 
<div class="shell">
 
    {{-- ═══════════════════════════════════════════════════════════════════════════
         HEADER
         ═══════════════════════════════════════════════════════════════════════════ --}}
    <header class="topbar">
        <div class="logo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="{{ '#D9B872' }}" stroke-width="1.6" aria-hidden="true">
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
 
        {{-- ═══════════════════════════════════════════════════════════════════════
             SIDEBAR
             ═══════════════════════════════════════════════════════════════════════ --}}
        <nav class="sidebar" aria-label="Navegação do admin">
 
            {{-- Visão geral --}}
            <div class="nav-group">
                <span class="group-label">Visão geral</span>
                <a href="{{ route('admin.dashboard') }}" class="nav-item active">
                    <span class="dot"></span>Painel de Receita
                </a>
            </div>
 
            {{-- Dinheiro --}}
            <div class="nav-group">
                <span class="group-label">Dinheiro</span>
                <a href="#payouts" class="nav-item nav-sub">
                    Payouts
                    @if ($pendingPayouts->isNotEmpty())
                        <span class="badge badge-gold">{{ $pendingPayouts->count() }}</span>
                    @endif
                </a>
                <a href="#ledger" class="nav-item nav-sub">Ledger &amp; extratos</a>
            </div>
 
            {{-- Pessoas --}}
            <div class="nav-group">
                <span class="group-label">Pessoas</span>
                <a href="#performers" class="nav-item nav-sub">Performers</a>
                <a href="#membros" class="nav-item nav-sub">Membros</a>
                <a href="{{ route('admin.kyc.panel') }}" class="nav-item nav-sub">
                    Fila de KYC
                    @if ($platform['pending_kyc'] > 0)
                        <span class="badge badge-warn">{{ $platform['pending_kyc'] }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.waitlist') }}" class="nav-item nav-sub">Waitlist</a>
            </div>
 
            {{-- Confiança & segurança --}}
            <div class="nav-group">
                <span class="group-label">Confiança &amp; segurança</span>
                <a href="{{ route('admin.reports') }}" class="nav-item nav-sub">
                    Denúncias
                    @if ($platform['open_reports'] > 0)
                        <span class="badge badge-red">{{ $platform['open_reports'] }}</span>
                    @endif
                </a>
            </div>
 
            {{-- Ledger status card --}}
            <div class="ledger-card">
                <div class="status">
                    @if ($ledgerHealth['ok'])
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7FB68C" stroke-width="2.4" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>
                        <span style="color: var(--green);">Ledger íntegro</span>
                    @else
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#F87171" stroke-width="2.4" aria-hidden="true"><path d="M12 8v4m0 4h.01"></path><circle cx="12" cy="12" r="10"></circle></svg>
                        <span style="color: var(--red);">Divergências</span>
                    @endif
                </div>
                <span class="detail">append-only · {{ $tok($ledgerHealth['checked']) }} wallets verificadas</span>
            </div>
        </nav>
 
        {{-- ═══════════════════════════════════════════════════════════════════════
             CONTEÚDO PRINCIPAL
             ═══════════════════════════════════════════════════════════════════════ --}}
        <main class="main">
 
            {{-- Título --}}
            <div class="title-row">
                <div>
                    <h1>Painel de Receita</h1>
                    <p class="sub">Agregados do ledger e contadores. Nenhum dado pessoal de membro — só números.</p>
                </div>
            </div>
 
            {{-- ── Alertas ────────────────────────────────────────────────────── --}}
            @if ($pendingPayouts->isNotEmpty() || $platform['pending_kyc'] > 0 || $platform['open_reports'] > 0)
                <div class="alerts">
                    @if ($pendingPayouts->isNotEmpty())
                        <div class="alert alert-warn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="{{ '#E0A85C' }}" stroke-width="1.9" aria-hidden="true"><path d="M12 4l9 16H3z"></path><path d="M12 10v4"></path><path d="M12 17.2v.1"></path></svg>
                            <div>
                                <div class="title" style="color: var(--warn-light);">{{ $pendingPayouts->count() }} {{ $pendingPayouts->count() === 1 ? 'payout aguardando' : 'payouts aguardando' }} revisão</div>
                            </div>
                            <a href="#payouts" class="link" style="color: var(--gold-light);">Revisar →</a>
                        </div>
                    @endif
                    @if ($platform['pending_kyc'] > 0)
                        <div class="alert alert-red">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="{{ '#E58479' }}" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5V12l3 2"></path></svg>
                            <div>
                                <div class="title" style="color: var(--red);">{{ $platform['pending_kyc'] }} KYC pendente{{ $platform['pending_kyc'] > 1 ? 's' : '' }}</div>
                            </div>
                            <a href="{{ route('admin.kyc.panel') }}" class="link" style="color: #E58479;">Abrir →</a>
                        </div>
                    @endif
                    @if ($platform['open_reports'] > 0)
                        <div class="alert alert-mute">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="{{ '#96C3D2' }}" stroke-width="1.9" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"></path></svg>
                            <div>
                                <div class="title" style="color: #DCE9EE;">{{ $platform['open_reports'] }} {{ $platform['open_reports'] === 1 ? 'denúncia aberta' : 'denúncias abertas' }}</div>
                            </div>
                            <a href="{{ route('admin.reports') }}" class="link" style="color: var(--teal-light);">Ver →</a>
                        </div>
                    @endif
                </div>
            @endif
 
            {{-- ── KPI Cards ──────────────────────────────────────────────────── --}}
            <div class="kpis">
                <div class="kpi">
                    <span class="label">Tokens vendidos</span>
                    <span class="value">{{ $tok($r30['tokens_sold']) }}</span>
                    <span class="note">últimos 30 dias</span>
                </div>
                <div class="kpi">
                    <span class="label">Receita bruta</span>
                    <span class="value color-gold">{{ $brl($r30['gross_revenue_cents']) }}</span>
                    <span class="note">{{ $r30['is_estimate'] ? 'estimada' : 'real (Asaas/PIX)' }}</span>
                </div>
                <div class="kpi">
                    <span class="label">Retenção Limen</span>
                    <span class="value">{{ $tok($r30['retention']) }}</span>
                    <span class="note">tokens · vendidos − pagos</span>
                </div>
                <div class="kpi">
                    <span class="label">Payout pendente</span>
                    <span class="value" style="color: var(--warn-light);">{{ $pendingPayouts->count() }}</span>
                    <span class="note">performers em revisão</span>
                </div>
                <div class="kpi">
                    <span class="label">Ativos (7d)</span>
                    <span class="value">{{ $tok($counters['active_members']) }}</span>
                    <span class="note">membros · {{ $tok($counters['active_performers']) }} performers</span>
                </div>
            </div>
 
            {{-- ── Vendidos × gastos + Gasto por vertical ─────────────────────── --}}
            <div class="chart-row">
                {{-- Gráfico: Vendidos × gastos --}}
                <div class="card card-pad">
                    <div class="chart-header">
                        <h2>Vendidos × gastos</h2>
                        <span class="card-sub">tokens, últimos 30 dias</span>
                        <div class="chart-legend">
                            <span><span class="swatch" style="background: var(--gold);"></span>vendidos</span>
                            <span><span class="swatch" style="background: var(--teal);"></span>gastos</span>
                        </div>
                    </div>
 
                    @php
                        $sold30 = max(1, $r30['tokens_sold']);
                        $spent30 = $r30['spent_total'];
                        // Barras proporcionais: vendido = 100%, cada vertical proporcional
                        $categories = ['chat', 'gorjeta', 'presente', 'conteudo', 'live', 'chamada'];
                        $barMax = max(1, $sold30);
                    @endphp
                    <div class="chart-bars">
                        @foreach ($categories as $cat)
                            @php
                                $catSpent = $r30['spent_by_type'][$cat] ?? 0;
                                // Barra gold: proporção do vendido (dividido por 6 para distribuir)
                                $soldPart = $sold30 / max(1, count($categories));
                                $goldH = min(100, max(3, ($soldPart / $barMax) * 100));
                                $tealH = min(100, max(($catSpent > 0 ? 3 : 0), ($catSpent / $barMax) * 100));
                            @endphp
                            <div class="bar-pair">
                                <div class="bar bar-gold" style="height: {{ $goldH }}%;"></div>
                                <div class="bar bar-teal" style="height: {{ $tealH }}%;"></div>
                            </div>
                        @endforeach
                    </div>
 
                    <div class="chart-footer">
                        <div class="chart-stat">
                            <span class="stat-label">Total vendidos</span>
                            <span class="stat-value">{{ $tok($r30['tokens_sold']) }}</span>
                        </div>
                        <div class="chart-stat">
                            <span class="stat-label">Total gastos</span>
                            <span class="stat-value">{{ $tok($r30['spent_total']) }}</span>
                        </div>
                        <div class="chart-stat">
                            <span class="stat-label">Em circulação</span>
                            <span class="stat-value">{{ $tok($r30['tokens_sold'] - $r30['spent_total'] - $r30['tokens_paid_out']) }}</span>
                        </div>
                    </div>
                </div>
 
                {{-- Gasto por vertical --}}
                <div class="card card-pad" style="display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <h2>Gasto por vertical</h2>
                        <span class="card-sub">30 dias · split aplicado no lançamento</span>
                    </div>
 
                    <div class="vert-list">
                        @foreach ($splits as $label => $s)
                            @php
                                $isLiveKit = in_array($label, ['live', 'chamada']);
                                $pctWidth = $maxSpend > 0 ? min(100, ($s['spent'] / $maxSpend) * 100) : 0;
                            @endphp
                            <div class="vert-item">
                                <div class="vert-head">
                                    <span class="name">{{ $spendLabels[$label] ?? $label }}</span>
                                    <span class="split-tag {{ $isLiveKit ? 'split-blue' : 'split-green' }}">{{ $pct($s['rate']) }}/{{ $pct(1 - $s['rate']) }}</span>
                                    <span class="amount">{{ $tok($s['spent']) }}</span>
                                </div>
                                <div class="vert-bar">
                                    <div class="fill {{ $isLiveKit ? 'fill-teal' : 'fill-gold' }}" style="width: {{ $pctWidth }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
 
                    <p class="vert-note">70/30 onde há custo de infraestrutura (LiveKit). 80/20 no resto. O split é gravado no lançamento.</p>
                </div>
            </div>
 
            {{-- ── Receita Hoje / 30d ─────────────────────────────────────────── --}}
            <div class="revenue-grid">
                @foreach ($periods as $key => $label)
                    @php $r = $revenue[$key]; @endphp
                    <div class="rev-card">
                        <h3>
                            {{ $label }}
                            @if ($r['is_estimate'])
                                <span class="est-badge">Estimada</span>
                            @endif
                        </h3>
                        <div class="rev-metrics">
                            <div class="rev-metric">
                                <div class="label">Receita bruta</div>
                                <div class="value color-gold">{{ $brl($r['gross_revenue_cents']) }}</div>
                            </div>
                            <div class="rev-metric">
                                <div class="label">Tokens vendidos</div>
                                <div class="value">{{ $tok($r['tokens_sold']) }}</div>
                            </div>
                            <div class="rev-metric">
                                <div class="label">Tokens pagos</div>
                                <div class="value">{{ $tok($r['tokens_paid_out']) }}</div>
                            </div>
                            <div class="rev-metric">
                                <div class="label">Retenção</div>
                                <div class="value color-green">{{ $tok($r['retention']) }}<span class="unit">tk</span></div>
                            </div>
                        </div>
                        <div class="spend-grid">
                            @foreach ($spendLabels as $skey => $slabel)
                                <div class="spend-metric">
                                    <div class="label">{{ $slabel }}</div>
                                    <div class="value">{{ $tok($r['spent_by_type'][$skey] ?? 0) }}<span class="unit">tk</span></div>
                                </div>
                            @endforeach
                        </div>
                        @if ($r['is_estimate'])
                            <p class="est-note">Receita = tokens × preço médio do pacote. Estimativa — sem cobrança confirmada.</p>
                        @else
                            <p class="est-note">Receita = soma das cobranças confirmadas (Asaas/PIX). Valor real.</p>
                        @endif
                    </div>
                @endforeach
            </div>
 
            {{-- ── Atividade ──────────────────────────────────────────────────── --}}
            <div class="kpis" style="grid-template-columns: repeat(4, 1fr);">
                <div class="kpi">
                    <span class="label">Membros ativos (7d)</span>
                    <span class="value color-gold">{{ $tok($counters['active_members']) }}</span>
                </div>
                <div class="kpi">
                    <span class="label">Performers ativas (7d)</span>
                    <span class="value color-gold">{{ $tok($counters['active_performers']) }}</span>
                </div>
                <div class="kpi">
                    <span class="label">Lives hoje</span>
                    <span class="value">{{ $tok($counters['lives_today']) }}</span>
                </div>
                <div class="kpi">
                    <span class="label">Chamadas hoje</span>
                    <span class="value">{{ $tok($counters['calls_today']) }}</span>
                </div>
            </div>
 
            {{-- ── Visão Geral da Plataforma ──────────────────────────────────── --}}
            <div class="card card-pad">
                <h2 style="margin-bottom: 12px;">Visão Geral da Plataforma</h2>
                <div class="kpis" style="grid-template-columns: repeat(4, 1fr);">
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Total Membros</span>
                        <span class="value color-gold">{{ $tok($platform['total_members']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Total Performers</span>
                        <span class="value color-gold">{{ $tok($platform['total_performers']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Waitlist</span>
                        <span class="value">{{ $tok($platform['waitlist_total']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Performers ativas</span>
                        <span class="value color-green">{{ $tok($platform['performers_active']) }}</span>
                    </div>
                </div>
                <div class="kpis" style="grid-template-columns: repeat(4, 1fr); margin-top: 8px;">
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Novos membros hoje</span>
                        <span class="value color-green">{{ $tok($platform['new_members_today']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Novos membros 7d</span>
                        <span class="value">{{ $tok($platform['new_members_7d']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Novos membros 30d</span>
                        <span class="value">{{ $tok($platform['new_members_30d']) }}</span>
                    </div>
                    <div class="kpi" style="background: var(--surface-2);">
                        <span class="label">Performers banidas</span>
                        <span class="value color-red">{{ $tok($platform['performers_banned']) }}</span>
                    </div>
                </div>
            </div>
 
            {{-- ── Payouts em revisão ─────────────────────────────────────────── --}}
            <div class="card" id="payouts">
                <div class="payout-header">
                    <h2>Fila de payout</h2>
                    @if ($pendingPayouts->isNotEmpty())
                        <span class="count">{{ $pendingPayouts->count() }}</span>
                    @endif
                    <span class="card-sub">payouts aguardando revisão</span>
                </div>
 
                @if ($pendingPayouts->isEmpty())
                    <div class="card-pad">
                        <p class="empty">Nenhum payout em revisão.</p>
                    </div>
                @else
                    <div class="table-wrap">
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
                                        <td style="font-weight: 600;">{{ $payout['performer'] }}</td>
                                        <td class="num">{{ $tok($payout['tokens']) }}</td>
                                        <td class="num" style="color: var(--gold-light); font-weight: 600;">R$ {{ number_format((float) $payout['amount_brl'], 2, ',', '.') }}</td>
                                        <td><span class="pill">{{ $payout['period'] }}</span></td>
                                        <td>{{ optional($payout['requested_at'])->format('d/m/Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
 
            {{-- ── Saúde + Ledger ─────────────────────────────────────────────── --}}
            <div class="health-row">
                {{-- Integridade do Ledger --}}
                <div class="card card-pad" id="ledger" style="display: flex; flex-direction: column; gap: 11px;">
                    <h2>Integridade do Ledger</h2>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="health-item">
                            <span class="dot {{ $ledgerHealth['ok'] ? 'dot-ok' : 'dot-err' }}"></span>
                            <span class="name" style="font-weight: 600; {{ $ledgerHealth['ok'] ? 'color: var(--green);' : 'color: var(--red);' }}">
                                {{ $ledgerHealth['ok'] ? 'Saudável' : 'Divergências encontradas' }}
                            </span>
                        </span>
                        <span style="color: var(--ink-mute); font-size: 12px;">{{ $tok($ledgerHealth['checked']) }} wallets verificadas</span>
                    </div>
 
                    @if (!$ledgerHealth['ok'])
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Wallet</th>
                                        <th class="num">Saldo (wallet)</th>
                                        <th class="num">Soma (ledger)</th>
                                        <th class="num">Diferença</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ledgerHealth['divergences'] as $d)
                                        <tr>
                                            <td>#{{ $d['wallet_id'] }}</td>
                                            <td class="num">{{ $tok($d['wallet_balance']) }}</td>
                                            <td class="num">{{ $tok($d['ledger_sum']) }}</td>
                                            <td class="num status-warn">{{ $tok($d['diff']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="empty">wallet.balance == SUM(ledger.amount) para todas as wallets.</p>
                    @endif
                </div>
 
                {{-- Filas Pendentes --}}
                <div class="card card-pad" style="display: flex; flex-direction: column; gap: 11px;">
                    <h2>Filas Pendentes</h2>
                    <div class="health-item">
                        <span class="dot {{ $platform['pending_kyc'] > 0 ? 'dot-warn' : 'dot-ok' }}"></span>
                        <span class="name">KYC pendente</span>
                        <span class="info" style="{{ $platform['pending_kyc'] > 0 ? 'color: var(--warn);' : '' }}">{{ $platform['pending_kyc'] }}</span>
                    </div>
                    <div class="health-item">
                        <span class="dot {{ $platform['open_reports'] > 0 ? 'dot-err' : 'dot-ok' }}"></span>
                        <span class="name">Denúncias abertas</span>
                        <span class="info" style="{{ $platform['open_reports'] > 0 ? 'color: var(--red);' : '' }}">{{ $platform['open_reports'] }}</span>
                    </div>
                    <div class="health-item">
                        <span class="dot dot-ok"></span>
                        <span class="name">Novas performers hoje</span>
                        <span class="info">{{ $platform['new_performers_today'] }}</span>
                    </div>
                    @if ($platform['pending_kyc'] > 0)
                        <div style="margin-top: auto;">
                            <a href="{{ route('admin.kyc.panel') }}" style="font-size: 12px; font-weight: 700;">Ver fila de KYC →</a>
                        </div>
                    @endif
                </div>
            </div>
 
            {{-- ── Performers em revisão por no-show ──────────────────────────── --}}
            @if ($strikeReviewPerformers->isNotEmpty())
                <div class="card">
                    <div class="payout-header">
                        <h2>Performers em Revisão por No-Show</h2>
                        <span class="count" style="color: #F0A99E; background: #4A2420;">{{ $strikeReviewPerformers->count() }}</span>
                    </div>
                    <div class="table-wrap">
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
                                        <td style="font-weight: 600;">{{ $row['performer'] }}</td>
                                        <td class="num" style="color: var(--red);">{{ $row['strikes'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
 
        </main>
    </div>
 
    {{-- ── Bottom branding ────────────────────────────────────────────────────── --}}
    <div class="bottom-bar">
        <span class="bottom-logo">LIMEN</span>
    </div>
 
</div>
</body>
</html>
