<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>{{ $isPerformer ? 'Confirme sua reserva' : 'Confirme seu lugar' }} — Limen</title>
</head>
{{-- Inline styles only (email clients strip <style>). Palette: fundo #0a0a0a,
     creme #F5F0E8, dourado #C9A84C. Portal mark drawn with CSS borders so it
     renders in Gmail (no <svg>/data-URI). No founder position and no invite URL
     appear anywhere in this email — by design (see WaitlistConfirmationMail). --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Hidden preheader (inbox preview). Neutral — never reveals position. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Falta um passo: confirme para garantir seu lugar no Limen Founding Members.
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

                    {{-- Greeting --}}
                    <tr>
                        <td align="center" style="padding:30px 40px 0 40px;">
                            <p style="margin:0; font-size:17px; line-height:1.5; color:#9a938a;">Olá, {{ $firstName }}.</p>
                        </td>
                    </tr>

                    {{-- Headline --}}
                    <tr>
                        <td align="center" style="padding:10px 40px 0 40px;">
                            <h1 style="margin:0; font-size:28px; line-height:1.3; font-weight:normal; color:#F5F0E8;">
                                {{ $isPerformer ? 'Sua reserva foi confirmada.' : 'Seu lugar está reservado.' }}
                            </h1>
                        </td>
                    </tr>

                    {{-- Founder label (role-aware, no number) --}}
                    <tr>
                        <td align="center" style="padding:22px 40px 0 40px;">
                            <span style="display:inline-block; font-size:12px; letter-spacing:3px; color:#C9A84C; text-transform:uppercase; border:1px solid #C9A84C; border-radius:999px; padding:8px 18px;">
                                {{ $founderTitle }}
                            </span>
                        </td>
                    </tr>

                    {{-- Short body --}}
                    <tr>
                        <td align="center" style="padding:26px 44px 0 44px;">
                            <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">
                                @if ($isPerformer)
                                    O Limen é uma plataforma verificada e exclusiva. Você está entre as primeiras criadoras convidadas. Seu espaço está guardado até a abertura.
                                @else
                                    Você está entre os primeiros. Quando abrirmos as portas, você entra antes de todos.
                                @endif
                            </p>
                        </td>
                    </tr>

                    {{-- Primary CTA: confirm (single button) --}}
                    <tr>
                        <td align="center" style="padding:32px 44px 0 44px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#C9A84C;">
                                        <a href="{{ $confirmUrl }}" style="display:inline-block; padding:15px 44px; font-size:16px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                                            {{ $isPerformer ? 'Confirmar minha identidade' : 'Confirmar meu lugar' }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Separator + discreet panel link (text, not a button) --}}
                    <tr>
                        <td style="padding:34px 44px 0 44px;">
                            <div style="border-top:1px solid #262626; padding-top:24px; text-align:center;">
                                <a href="{{ $panelUrl }}" style="font-size:14px; color:#9a938a; text-decoration:underline;">
                                    {{ $isPerformer ? 'Acessar meu painel de fundadora' : 'Acessar meu painel de fundador' }}
                                </a>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:30px 44px 44px 44px;">
                            <div style="text-align:center;">
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
