<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · Membros — Limen</title>
    <style>
        /* Fontes self-hosted (public/fonts) — área logada não fala com terceiro. */
        @font-face { font-family: 'Cormorant Garamond'; font-style: normal; font-weight: 300 700; font-display: swap;
            src: url('/fonts/cormorant-garamond-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
        @font-face { font-family: 'Cormorant Garamond'; font-style: normal; font-weight: 300 700; font-display: swap;
            src: url('/fonts/cormorant-garamond-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
        @font-face { font-family: 'Manrope'; font-style: normal; font-weight: 200 800; font-display: swap;
            src: url('/fonts/manrope-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
        @font-face { font-family: 'Manrope'; font-style: normal; font-weight: 200 800; font-display: swap;
            src: url('/fonts/manrope-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
        @font-face { font-family: 'JetBrains Mono'; font-style: normal; font-weight: 100 800; font-display: swap;
            src: url('/fonts/jetbrains-mono-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
        @font-face { font-family: 'JetBrains Mono'; font-style: normal; font-weight: 100 800; font-display: swap;
            src: url('/fonts/jetbrains-mono-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
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
        body { background: var(--bg); color: var(--ink); font-family: "Manrope", system-ui, sans-serif; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--gold); text-decoration: none; } a:hover { color: #F0D49A; }
        .shell { display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { height: 60px; flex-shrink: 0; padding: 0 24px; display: flex; align-items: center; gap: 16px; background: var(--surface); border-bottom: 1px solid var(--line); }
        .topbar .logo { display: flex; align-items: center; gap: 10px; }
        .topbar .logo-text { font-family: "Cormorant Garamond", Georgia, serif; font-size: 21px; font-weight: 600; letter-spacing: 0.2em; color: var(--ink); }
        .topbar .divider { width: 1px; height: 24px; background: var(--line); }
        .topbar .admin-badge { padding: 4px 11px; border-radius: 999px; background: #33261A; border: 1px solid var(--gold-border); color: var(--gold-light); font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .topbar .env-badge { padding: 4px 10px; border-radius: 6px; background: var(--teal-bg); border: 1px solid var(--teal-border); color: var(--teal-light); font-size: 10.5px; font-weight: 700; letter-spacing: 0.1em; }
        .topbar .spacer { flex-grow: 1; }
        .body-row { flex-grow: 1; display: flex; min-height: 0; }
        .sidebar { width: 240px; flex-shrink: 0; padding: 20px 14px; background: #171310; border-right: 1px solid var(--line); display: flex; flex-direction: column; gap: 18px; overflow-y: auto; }
        .sidebar .group-label { padding: 0 10px 3px; font-size: 10px; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase; color: var(--ink-mute); }
        .sidebar .nav-group { display: flex; flex-direction: column; gap: 3px; }
        .sidebar .nav-item { display: flex; align-items: center; gap: 9px; min-height: 36px; padding: 7px 10px; border-radius: 8px; color: #BDB2A3; font-size: 13px; font-weight: 500; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.03); color: var(--ink); }
        .sidebar .nav-item.active { background: #2A2017; color: var(--gold-light); font-weight: 600; }
        .sidebar .nav-item .dot { width: 5px; height: 5px; border-radius: 999px; background: var(--gold); flex-shrink: 0; }
        .sidebar .nav-sub { padding-left: 24px; }
        .main { flex-grow: 1; padding: 24px 28px; display: flex; flex-direction: column; gap: 18px; min-width: 0; overflow-y: auto; max-width: 760px; }
        .title-row h1 { font-family: "Cormorant Garamond", Georgia, serif; font-size: 28px; font-weight: 600; color: #F6F1E8; }
        .title-row .sub { font-size: 12px; color: var(--ink-dim); margin-top: 3px; }
        .flash { border-radius: 11px; padding: 11px 15px; font-size: 13px; font-weight: 600; }
        .flash-ok { background: var(--green-bg); border: 1px solid var(--green-border); color: var(--green); }
        .flash-info { background: var(--teal-bg); border: 1px solid var(--teal-border); color: var(--teal-light); }
        .flash-err { background: var(--red-bg); border: 1px solid var(--red-border); color: var(--red); }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 13px; padding: 18px 20px; }
        .search-form { display: flex; gap: 8px; }
        .search-form input { flex-grow: 1; min-height: 44px; padding: 0 14px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface-2); color: var(--ink); font: inherit; font-size: 14px; }
        .search-form input:focus { outline: none; border-color: var(--gold); }
        .search-form button { min-height: 44px; padding: 0 20px; border-radius: 10px; border: 1px solid var(--gold-border); background: var(--gold-bg); color: var(--gold-light); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
        .hint { font-size: 11.5px; color: var(--ink-mute); margin-top: 8px; }
        .rows { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .row { padding: 12px 14px; background: var(--surface-2); border: 1px solid var(--line); border-radius: 10px; }
        .row .label { font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-mute); margin-bottom: 4px; }
        .row .value { font-size: 15px; font-weight: 600; color: var(--ink); }
        .row .value.mono { font-family: "JetBrains Mono", monospace; }
        .tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; border: 1px solid var(--line); color: var(--ink-mute); }
        .tag.st-active { border-color: var(--green-border); color: var(--green); background: var(--green-bg); }
        .tag.st-pending { border-color: var(--warn-border); color: var(--warn-light); background: var(--warn-bg); }
        .tag.st-suspended { border-color: var(--warn-border); color: var(--warn); background: var(--warn-bg); }
        .tag.st-banned { border-color: var(--red-border); color: var(--red); background: var(--red-bg); }
        .tag.hot { border-color: var(--red-border); color: var(--red); background: var(--red-bg); }
        .actions { display: flex; flex-direction: column; gap: 10px; }
        .action-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        details.act > summary { list-style: none; cursor: pointer; min-height: 40px; display: inline-flex; align-items: center; gap: 8px; padding: 0 15px; border-radius: 9px; border: 1px solid var(--line); color: var(--ink-soft); font-size: 13px; font-weight: 600; }
        details.act > summary::-webkit-details-marker { display: none; }
        details.act.warn > summary:hover { border-color: var(--warn-border); color: var(--warn-light); }
        details.act.danger > summary:hover { border-color: var(--red-border); color: var(--red); }
        details.act.glass > summary:hover { border-color: var(--gold-border); color: var(--gold-light); }
        .act-body { margin-top: 10px; padding: 14px; background: var(--surface-2); border: 1px solid var(--line); border-radius: 10px; max-width: 420px; }
        .act-body.danger { border-color: var(--red-border); } .act-body.glass { border-color: var(--gold-border); }
        .act-body p { font-size: 12px; color: var(--ink-mute); margin-bottom: 10px; }
        .act-body textarea { display: block; width: 100%; margin-bottom: 10px; padding: 9px; font: inherit; font-size: 13px; background: var(--bg); color: var(--ink); border: 1px solid var(--line); border-radius: 8px; resize: vertical; min-height: 60px; }
        .act-body button { min-height: 40px; padding: 0 16px; border-radius: 9px; border: 1px solid var(--line); background: var(--surface); color: var(--ink-soft); font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; width: 100%; }
        .act-body.danger button { border-color: var(--red-border); color: var(--red); background: var(--red-bg); }
        .act-body.glass button { border-color: var(--gold-border); color: var(--gold-light); background: var(--gold-bg); }
        .btn-plain { min-height: 40px; display: inline-flex; align-items: center; padding: 0 15px; border-radius: 9px; border: 1px solid var(--line); color: var(--ink-soft); font-size: 13px; font-weight: 600; }
        .btn-plain:hover { border-color: var(--gold-border); color: var(--gold-light); }
        .reveal-box { border: 1px solid var(--gold-border); background: #241B10; border-radius: 12px; padding: 16px 18px; }
        .reveal-box h3 { font-size: 13px; color: var(--gold-light); margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .reveal-box .id-line { font-size: 15px; color: var(--ink); }
        .reveal-box .id-line b { color: var(--gold-light); }
        .reveal-box .meta { font-size: 11px; color: var(--ink-mute); margin-top: 10px; }
        .empty { color: var(--ink-mute); font-size: 13px; }
        @media (max-width: 800px) {
            .sidebar { display: none; } .topbar { padding: 0 16px; } .main { padding: 20px 16px; } .rows { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
@php
    $statusLabels = ['active' => 'Ativo', 'pending' => 'Pendente', 'suspended' => 'Suspenso', 'banned' => 'Banido'];
@endphp
<div class="shell">
    <header class="topbar">
        <div class="logo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D9B872" stroke-width="1.6" aria-hidden="true"><path d="M4 21V11a8 8 0 0 1 16 0v10"></path></svg>
            <span class="logo-text">LIMEN</span>
        </div>
        <div class="divider"></div>
        <span class="admin-badge">Painel Admin</span>
        @if (! app()->environment('production'))
            <span class="env-badge">{{ strtoupper(app()->environment()) }}</span>
        @endif
        <div class="spacer"></div>
    </header>

    <div class="body-row">
        <nav class="sidebar" aria-label="Navegação do admin">
            <div class="nav-group">
                <span class="group-label">Visão geral</span>
                <a href="{{ route('admin.dashboard') }}" class="nav-item"><span class="dot"></span>Painel de Receita</a>
            </div>
            <div class="nav-group">
                <span class="group-label">Pessoas</span>
                <a href="{{ route('admin.performers') }}" class="nav-item nav-sub">Performers</a>
                <a href="{{ route('admin.members') }}" class="nav-item nav-sub active">Membros</a>
                <a href="{{ route('admin.kyc.panel') }}" class="nav-item nav-sub">Fila de KYC</a>
                <a href="{{ route('admin.waitlist') }}" class="nav-item nav-sub">Waitlist</a>
            </div>
            <div class="nav-group">
                <span class="group-label">Confiança &amp; segurança</span>
                <a href="{{ route('admin.reports') }}" class="nav-item nav-sub">Denúncias</a>
            </div>
        </nav>

        <main class="main">
            <div class="title-row">
                <h1>Membros — segurança por ID</h1>
                <p class="sub">O membro é anônimo por design. Busque por ID para agir sobre um caso; a identidade só aparece por revelação auditada.</p>
            </div>

            @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
            @if (session('info'))<div class="flash flash-info">{{ session('info') }}</div>@endif
            @if ($errors->any())<div class="flash flash-err">{{ $errors->first() }}</div>@endif

            {{-- Busca por ID --}}
            <div class="card">
                <form class="search-form" method="GET" action="{{ route('admin.members') }}" role="search">
                    <input type="text" inputmode="numeric" name="id" value="{{ $query }}" placeholder="ID do membro (ex.: 12345)" aria-label="ID do membro">
                    <button type="submit">Buscar</button>
                </form>
                <p class="hint">Busca só por ID numérico do membro — sem busca por nome/e-mail (é o que mantém o anonimato). CPF e documento seguem no painel do provider (Didit).</p>
            </div>

            {{-- Identidade revelada (uma vez) --}}
            @if ($revealed)
                <div class="reveal-box">
                    <h3>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#E8C87D" stroke-width="1.9" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        Identidade revelada — membro #{{ $revealed['id'] }}
                    </h3>
                    <div class="id-line">Nome: <b>{{ $revealed['name'] ?? '—' }}</b></div>
                    <div class="id-line">E-mail: <b>{{ $revealed['email'] ?? '—' }}</b></div>
                    <div class="meta">Revelação registrada em auditoria (motivo: "{{ $revealed['reason'] }}"). Mostrada uma única vez — recarregar a página a esconde. CPF/documento não entram aqui.</div>
                </div>
            @endif

            {{-- Resultado --}}
            @if ($notFound)
                <div class="card"><p class="empty">Nenhum membro com o ID "{{ $query }}". (A busca é só por ID numérico de conta de membro.)</p></div>
            @elseif ($member)
                <div class="card">
                    <div class="rows">
                        <div class="row">
                            <div class="label">Membro</div>
                            <div class="value mono">#{{ $member->id }}</div>
                        </div>
                        <div class="row">
                            <div class="label">Status da conta</div>
                            <div class="value"><span class="tag st-{{ $stats['status'] }}">{{ $statusLabels[$stats['status']] ?? $stats['status'] }}</span></div>
                        </div>
                        <div class="row">
                            <div class="label">Cadastro</div>
                            <div class="value mono">{{ optional($stats['created_at'])->format('d/m/Y') ?? '—' }}</div>
                        </div>
                        <div class="row">
                            <div class="label">Último login</div>
                            <div class="value mono">{{ optional($stats['last_login_at'])->format('d/m/Y') ?? '—' }}</div>
                        </div>
                        <div class="row">
                            <div class="label">Denúncias abertas contra</div>
                            <div class="value">@if ($stats['open_reports'] > 0)<span class="tag hot">{{ $stats['open_reports'] }}</span>@else <span class="value mono">0</span>@endif</div>
                        </div>
                        <div class="row">
                            <div class="label">Antifraude</div>
                            <div class="value">@if ($stats['blacklist_hit'])<span class="tag hot">na blacklist</span>@else <span class="tag st-active">limpo</span>@endif</div>
                        </div>
                    </div>
                </div>

                {{-- Ações --}}
                <div class="card">
                    <div class="actions">
                        <div class="action-row">
                            <a href="{{ route('admin.reports') }}" class="btn-plain">Ver fila de denúncias →</a>
                        </div>

                        <div class="action-row">
                            {{-- Suspender / Reativar --}}
                            @if ($stats['status'] === 'suspended')
                                <form method="POST" action="{{ route('admin.members.reactivate', $member->id) }}">
                                    @csrf
                                    <button type="submit" class="btn-plain" style="border-color: var(--green-border); color: var(--green);">Reativar conta</button>
                                </form>
                            @elseif ($stats['status'] !== 'banned')
                                <details class="act warn">
                                    <summary>Suspender (temporário)</summary>
                                    <div class="act-body">
                                        <p>Suspensão temporária bloqueia o login até ser reativada. Reversível.</p>
                                        <form method="POST" action="{{ route('admin.members.suspend', $member->id) }}">
                                            @csrf
                                            <textarea name="reason" required maxlength="500" placeholder="Motivo (obrigatório, vai no audit)"></textarea>
                                            <button type="submit">Confirmar suspensão</button>
                                        </form>
                                    </div>
                                </details>
                            @endif

                            {{-- Banir (reusa endpoint auditado) --}}
                            @if ($stats['status'] !== 'banned')
                                <details class="act danger">
                                    <summary>Banir (permanente)</summary>
                                    <div class="act-body danger">
                                        <p>Ban permanente encerra a conta e entra na blacklist antifraude. Irreversível por aqui.</p>
                                        <form method="POST" action="{{ route('admin.users.ban', $member->id) }}">
                                            @csrf
                                            <textarea name="reason" required maxlength="500" placeholder="Motivo do ban (obrigatório, vai no audit)"></textarea>
                                            <button type="submit">Confirmar ban permanente</button>
                                        </form>
                                    </div>
                                </details>
                            @else
                                <span class="tag st-banned">Conta banida</span>
                            @endif

                            {{-- Revelar identidade (break the glass) — só com a flag ligada --}}
                            @if (config('features.member_identity_reveal'))
                                <details class="act glass">
                                    <summary>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        Revelar identidade
                                    </summary>
                                    <div class="act-body glass">
                                        <p>Mostra nome e e-mail deste membro UMA vez, com registro de auditoria (quem/quando/por quê). Use só para ação jurídica ou de segurança. CPF/documento não entram aqui.</p>
                                        <form method="POST" action="{{ route('admin.members.reveal', $member->id) }}">
                                            @csrf
                                            <textarea name="reason" required maxlength="500" placeholder="Motivo / referência do caso (obrigatório, auditado)"></textarea>
                                            <button type="submit">Revelar e registrar</button>
                                        </form>
                                    </div>
                                </details>
                            @else
                                <span class="btn-plain" style="cursor: default; opacity: 0.7;" title="Aguardando parecer do jurídico (LGPD)">Revelar identidade — desativado</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </main>
    </div>
</div>
</body>
</html>
