<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Confirme seu lugar — Limen</title>
</head>
{{-- Inline styles only (email clients strip <style>). Palette: fundo #0a0a0a,
     creme #F5F0E8, dourado #C9A84C. Portal mark drawn with CSS borders so it
     renders in Gmail (no <svg>/data-URI). --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Você é o #{{ number_format($position, 0, ',', '.') }} da lista. Confirme seu e-mail e comece a subir de nível.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:18px;">

                    {{-- Portal mark --}}
                    <tr>
                        <td align="center" style="padding:44px 40px 8px 40px;">
                            <div style="width:68px; height:42px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:36px 36px 0 0;"></div>
                            <div style="width:84px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
                            <div style="margin-top:14px; font-size:14px; letter-spacing:6px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                            <div style="margin-top:6px; font-size:11px; letter-spacing:3px; color:#6f6a62; text-transform:uppercase;">Founding Members</div>
                        </td>
                    </tr>

                    {{-- Headline --}}
                    <tr>
                        <td align="center" style="padding:24px 40px 0 40px;">
                            <h1 style="margin:0; font-size:28px; line-height:1.25; font-weight:normal; color:#F5F0E8;">
                                Olá, {{ $firstName }}. Você está dentro.
                            </h1>
                        </td>
                    </tr>

                    {{-- Big position (per role) --}}
                    <tr>
                        <td align="center" style="padding:28px 40px 0 40px;">
                            <div style="font-size:13px; letter-spacing:2px; color:#9a938a; text-transform:uppercase;">Você é o</div>
                            <div style="font-size:20px; color:#F5F0E8; margin-top:6px;">{{ $founderTitle }}</div>
                            <div style="font-size:58px; line-height:1.1; color:#C9A84C; margin-top:2px;">#{{ number_format($position, 0, ',', '.') }}</div>
                        </td>
                    </tr>

                    {{-- Tier + next reward --}}
                    <tr>
                        <td style="padding:28px 44px 0 44px;">
                            <div style="border:1px solid #262626; border-radius:12px; padding:20px 22px;">
                                <p style="margin:0; font-size:16px; line-height:1.6; color:#F5F0E8;">
                                    Seu nível: <span style="color:#C9A84C;">{{ $tierLabel }}</span>.
                                </p>
                                @if ($nextTier)
                                    <p style="margin:8px 0 0 0; font-size:15px; line-height:1.6; color:#9a938a;">
                                        Convide mais <span style="color:#F5F0E8;">{{ $nextTier['remaining'] }}</span>
                                        {{ $nextTier['phrase'] }} e vire
                                        <span style="color:#C9A84C;">{{ $nextTier['label'] }}</span> — {{ $nextTier['benefit'] }}.
                                    </p>
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- Primary CTA: confirm email --}}
                    <tr>
                        <td align="center" style="padding:28px 44px 0 44px;">
                            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#9a938a;">
                                Falta um passo: confirme seu e-mail para garantir seu lugar.
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#C9A84C;">
                                        <a href="{{ $confirmUrl }}" style="display:inline-block; padding:15px 40px; font-size:16px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                                            Confirmar meu e-mail
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Invite link (copyable) --}}
                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <div style="border-top:1px solid #262626; padding-top:26px;">
                                <p style="margin:0 0 6px 0; font-size:15px; line-height:1.6; color:#F5F0E8;">
                                    Seu link de convite
                                </p>
                                <p style="margin:0 0 14px 0; font-size:13px; line-height:1.6; color:#9a938a;">
                                    Cada amigo que confirmar sobe você de nível.
                                </p>
                                <div style="background-color:#0a0a0a; border:1px solid #2f2f2f; border-radius:10px; padding:14px 16px; text-align:center;">
                                    <a href="{{ $inviteUrl }}" style="font-size:15px; color:#C9A84C; text-decoration:none; word-break:break-all;">{{ $inviteUrl }}</a>
                                </div>
                                <div style="text-align:center; margin-top:14px;">
                                    <a href="{{ $inviteUrl }}" style="display:inline-block; padding:11px 26px; font-size:14px; letter-spacing:1px; color:#C9A84C; text-decoration:none; border:1px solid #C9A84C; border-radius:999px;">
                                        Copiar meu link
                                    </a>
                                </div>
                                <p style="margin:16px 0 0 0; font-size:13px; text-align:center;">
                                    <a href="{{ $panelUrl }}" style="color:#9a938a; text-decoration:underline;">Ver meu painel de fundador</a>
                                </p>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:34px 44px 44px 44px;">
                            <div style="border-top:1px solid #262626; padding-top:22px; text-align:center;">
                                <p style="margin:0 0 10px 0; font-size:13px; letter-spacing:1px; color:#9a938a;">
                                    Limen · Brasil · +18
                                </p>
                                <p style="margin:0; font-size:12px; line-height:1.6; color:#6f6a62;">
                                    Não quer mais receber?
                                    <a href="{{ $unsubscribeUrl }}" style="color:#9a938a; text-decoration:underline;">Descadastrar da lista</a>.
                                </p>
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
