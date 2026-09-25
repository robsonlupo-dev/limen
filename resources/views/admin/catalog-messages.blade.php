<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin · Mensagens de catálogo — Limen</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #0a0a0a; color: #F5F0E8; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 40px 20px 64px; }
        a.back { color: #9a938a; font-size: 13px; text-decoration: none; }
        a.back:hover { color: #F5F0E8; }
        h1 { font-weight: 600; font-size: 22px; margin: 12px 0 4px; }
        .sub { color: #9a938a; font-size: 13px; margin: 0 0 24px; }
        .gold { color: #C9A84C; }
        .muted { color: #9a938a; }
        .flash { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 20px; }
        .flash.ok { background: #16241a; border: 1px solid #2b5133; color: #b8e3c2; }
        .card { border: 1px solid #262626; border-radius: 12px; padding: 18px; background: #0d0d0d; margin-bottom: 16px; }
        .card.off { opacity: .62; }
        .row { display: flex; gap: 12px; align-items: flex-start; }
        textarea { width: 100%; min-height: 64px; resize: vertical; background: #131313; color: #F5F0E8; border: 1px solid #2a2a2a; border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: inherit; }
        textarea:focus { outline: none; border-color: #C9A84C; }
        .meta { display: flex; gap: 16px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
        label.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #cfc7ba; }
        input[type=number] { width: 72px; background: #131313; color: #F5F0E8; border: 1px solid #2a2a2a; border-radius: 8px; padding: 6px 8px; font-size: 13px; }
        .lbl { font-size: 12px; color: #9a938a; }
        .actions { margin-left: auto; display: flex; gap: 8px; }
        button { font: inherit; cursor: pointer; border-radius: 8px; padding: 8px 14px; font-size: 13px; border: 1px solid transparent; }
        button.save { background: #C9A84C; color: #1a1400; font-weight: 600; }
        button.save:hover { background: #d8b95e; }
        button.del { background: transparent; border-color: #5a2a2a; color: #e58479; }
        button.del:hover { background: #2a1414; }
        .tag { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 2px 8px; border-radius: 999px; }
        .tag.on { background: #16241a; color: #7fce93; }
        .tag.no { background: #2a1414; color: #e58479; }
        .new h2 { font-size: 15px; margin: 0 0 12px; }
        .help { font-size: 12px; color: #6f6a62; margin-top: 8px; line-height: 1.5; }
        code { background: #1c1c1c; padding: 1px 5px; border-radius: 4px; color: #C9A84C; }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="{{ route('admin.dashboard') }}">← Painel</a>
        <h1>Mensagens de catálogo</h1>
        <p class="sub">
            As mensagens que a performer pode enviar no alcance grátis ao catálogo de membros.
            Ela <strong>escolhe uma destas</strong> — não digita texto livre.
            <span class="gold">{{ $activeCount }}</span> ativa(s).
        </p>

        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif

        <p class="help">
            Use <code>{nome}</code> no texto para inserir o apelido do membro no envio.
            Se o membro não tiver apelido, o <code>{nome}</code> some automaticamente (nunca aparece "Membro #0000").
            Não inclua contato (WhatsApp/Instagram/telefone) nem combinação de pagamento — estas mensagens são a porta de entrada grátis.
        </p>

        {{-- Lista + edição inline --}}
        @foreach ($templates as $t)
            <div class="card {{ $t->is_active ? '' : 'off' }}">
                <form method="POST" action="{{ route('admin.catalog-messages.update', $t) }}">
                    @csrf
                    @method('PATCH')
                    <div class="row">
                        <textarea name="body" maxlength="{{ (int) config('chat.max_length') }}" required>{{ $t->body }}</textarea>
                    </div>
                    <div class="meta">
                        <span class="tag {{ $t->is_active ? 'on' : 'no' }}">{{ $t->is_active ? 'Ativa' : 'Inativa' }}</span>
                        <label class="chk"><input type="checkbox" name="is_active" value="1" @checked($t->is_active)> Ativa</label>
                        <span class="lbl">Ordem</span>
                        <input type="number" name="position" value="{{ $t->position }}" min="0" max="9999">
                        <div class="actions">
                            <button type="submit" class="save">Salvar</button>
                        </div>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.catalog-messages.destroy', $t) }}" style="margin-top:8px; text-align:right;" onsubmit="return confirm('Remover esta mensagem em definitivo?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="del">Remover</button>
                </form>
            </div>
        @endforeach

        {{-- Adicionar nova --}}
        <div class="card new">
            <h2>Adicionar mensagem</h2>
            <form method="POST" action="{{ route('admin.catalog-messages.store') }}">
                @csrf
                <textarea name="body" maxlength="{{ (int) config('chat.max_length') }}" placeholder="Escreva a nova mensagem… (opcional: {nome})" required></textarea>
                <div class="meta">
                    <span class="lbl">Ordem</span>
                    <input type="number" name="position" min="0" max="9999" placeholder="fim">
                    <div class="actions">
                        <button type="submit" class="save">Adicionar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
