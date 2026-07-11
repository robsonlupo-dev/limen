<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Painel do Fundador — Limen</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background-color: #0a0a0a; color: #F5F0E8;
            font-family: Georgia, 'Times New Roman', serif;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 560px; margin: 0 auto; padding: 40px 20px 64px; }
        .card { border: 1px solid #262626; border-radius: 16px; padding: 24px; margin-top: 20px; background: #0d0d0d; }
        .gold { color: #C9A84C; }
        .muted { color: #9a938a; }
        .dim { color: #6f6a62; }
        .center { text-align: center; }
        .flash { background: rgba(201,168,76,0.08); border: 1px solid #C9A84C; color: #F5F0E8;
                 border-radius: 12px; padding: 14px 18px; text-align: center; font-size: 14px; margin-top: 20px; }
        .bar { height: 10px; background: #1c1c1c; border-radius: 999px; overflow: hidden; margin: 14px 0 8px; }
        .bar > span { display: block; height: 100%; background: #C9A84C; border-radius: 999px; }
        .btn { display: inline-block; padding: 14px 30px; border-radius: 999px; background: #C9A84C;
               color: #0a0a0a; text-decoration: none; border: none; font-size: 15px; letter-spacing: 1px;
               font-family: Georgia, serif; cursor: pointer; }
        .linkbox { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
        .linkbox input { flex: 1 1 200px; min-width: 0; background: #0a0a0a; border: 1px solid #2f2f2f;
               border-radius: 10px; padding: 13px 14px; color: #C9A84C; font-family: Georgia, serif; font-size: 14px; }
        .ladder { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-top: 1px solid #1c1c1c; }
        .ladder:first-child { border-top: none; }
        .dot { flex: 0 0 auto; width: 22px; height: 22px; border-radius: 999px; border: 1px solid #3a3a3a;
               color: #6f6a62; font-size: 12px; text-align: center; line-height: 21px; margin-top: 2px; }
        .dot.on { background: #C9A84C; border-color: #C9A84C; color: #0a0a0a; }
        .reflist li { list-style: none; padding: 10px 0; border-top: 1px solid #1c1c1c; display: flex; justify-content: space-between; }
        .reflist { margin: 8px 0 0; padding: 0; }
        .pill { font-size: 12px; padding: 2px 10px; border-radius: 999px; border: 1px solid #2f2f2f; }
        .pill.ok { color: #C9A84C; border-color: #C9A84C; }
    </style>
</head>
<body>
    <div class="wrap">
        {{-- Portal mark --}}
        <div class="center">
            <div style="width:60px;height:38px;margin:0 auto;border:2px solid #C9A84C;border-bottom:none;border-radius:32px 32px 0 0;"></div>
            <div style="width:76px;height:2px;margin:0 auto;background:#C9A84C;"></div>
            <div style="margin-top:12px;font-size:13px;letter-spacing:6px;text-transform:uppercase;" class="gold">Limen</div>
            <div style="margin-top:5px;font-size:10px;letter-spacing:3px;text-transform:uppercase;" class="dim">Founding Members</div>
        </div>

        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif

        <h1 style="margin:28px 0 0;font-size:26px;font-weight:normal;text-align:center;">
            Painel de {{ $firstName }}
        </h1>
        <p class="center gold" style="margin:6px 0 0;font-size:14px;letter-spacing:1px;">
            {{ $founderTitle }} #{{ number_format($position, 0, ',', '.') }}
        </p>
        @unless ($confirmed)
            <p class="center muted" style="margin:8px 0 0;font-size:14px;">
                Seu e-mail ainda não foi confirmado — confira sua caixa de entrada para garantir seu lugar.
            </p>
        @endunless

        {{-- Position (per role) --}}
        <div class="card center">
            <div class="muted" style="font-size:12px;letter-spacing:2px;text-transform:uppercase;">Sua posição</div>
            <div class="gold" style="font-size:52px;line-height:1.1;margin-top:4px;">#{{ number_format($position, 0, ',', '.') }}</div>
            <div class="dim" style="font-size:14px;">
                de {{ number_format($totalInRole, 0, ',', '.') }} {{ $isPerformer ? 'performers' : 'membros' }} na lista
            </div>
        </div>

        {{-- Tier + progress --}}
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                <div style="font-size:20px;">Nível: <span class="gold">{{ $tierLabel }}</span></div>
                <div class="muted" style="font-size:14px;">{{ $referralCount }} {{ $referralCount == 1 ? 'indicação' : 'indicações' }}</div>
            </div>
            <p class="muted" style="margin:6px 0 0;font-size:14px;">{{ $tierBenefit }}</p>

            @if ($nextTier)
                <div class="bar"><span style="width: {{ $nextTier['progress'] }}%;"></span></div>
                <p class="dim" style="margin:0;font-size:13px;">
                    Faltam <span class="gold">{{ $nextTier['remaining'] }}</span> {{ $nextTier['phrase'] }} para
                    <span style="color:#F5F0E8;">{{ $nextTier['label'] }}</span> — {{ $nextTier['benefit'] }}.
                </p>
            @else
                <p class="gold" style="margin:12px 0 0;font-size:14px;">Você alcançou o nível máximo. Lenda. 👑</p>
            @endif
        </div>

        {{-- Invite link --}}
        <div class="card">
            <div style="font-size:16px;">Seu link de convite</div>
            <p class="muted" style="margin:6px 0 0;font-size:14px;">Cada amigo que confirmar sobe você de nível.</p>
            <div class="linkbox">
                <input id="invite" type="text" readonly value="{{ $inviteUrl }}" aria-label="Seu link de convite">
                <button class="btn" type="button" id="copy" data-link="{{ $inviteUrl }}">Copiar</button>
            </div>
        </div>

        {{-- Benefits ladder --}}
        <div class="card">
            <div style="font-size:16px;margin-bottom:6px;">Benefícios</div>
            @foreach ($benefits as $b)
                <div class="ladder">
                    <div class="dot {{ $b['achieved'] ? 'on' : '' }}">{{ $b['achieved'] ? '✓' : '' }}</div>
                    <div>
                        <div style="font-size:15px;{{ $b['achieved'] ? '' : 'color:#9a938a;' }}">
                            {{ $b['label'] }} <span class="dim" style="font-size:13px;">· meta {{ $b['threshold'] }}</span>
                        </div>
                        <div class="{{ $b['achieved'] ? 'muted' : 'dim' }}" style="font-size:13px;">{{ $b['benefit'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Recent referrals --}}
        @if ($recentReferrals->isNotEmpty())
            <div class="card">
                <div style="font-size:16px;">Seus últimos indicados</div>
                <ul class="reflist">
                    @foreach ($recentReferrals as $r)
                        <li>
                            <span>{{ $r['name'] }}</span>
                            <span class="pill {{ $r['confirmed'] ? 'ok' : '' }}">{{ $r['confirmed'] ? 'confirmado' : 'pendente' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="center dim" style="margin-top:32px;font-size:12px;letter-spacing:1px;">Limen · Brasil · +18</p>
    </div>

    <script>
        // Real copy-to-clipboard (this is a standalone page, JS runs here — unlike email).
        document.getElementById('copy').addEventListener('click', async function () {
            var link = this.dataset.link;
            try {
                await navigator.clipboard.writeText(link);
            } catch (e) {
                var input = document.getElementById('invite');
                input.focus(); input.select(); document.execCommand('copy');
            }
            var original = this.textContent;
            this.textContent = 'Copiado!';
            setTimeout(() => { this.textContent = original; }, 1800);
        });
    </script>
</body>
</html>
