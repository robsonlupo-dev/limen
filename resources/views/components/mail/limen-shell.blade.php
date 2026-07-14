@props([
    'title',
    'preheader',
    'unsubscribeUrl',
])
{{-- Shared shell for Limen waitlist emails. Inline styles only (clients strip
     <style>). Palette: fundo #0a0a0a, creme #F5F0E8, dourado #C9A84C. Portal
     mark drawn with CSS borders so it renders in Gmail (no <svg>/data-URI).
     Nurturing emails carry NO founder position and NO invite/referral link —
     same discretion rule as the confirmation email. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>{{ $title }} — Limen</title>
</head>
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Hidden preheader (inbox preview). Neutral — never reveals position. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $preheader }}
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

                    {{-- Per-email body --}}
                    {{ $slot }}

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
