<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Você está na lista — Limen</title>
</head>
{{-- Inline styles only: email clients strip <style>/external CSS. Palette per the
     landing DNA: fundo #0a0a0a, creme #F5F0E8, dourado #C9A84C. The portal mark is
     built from CSS borders (not <svg>/data-URI) so it renders in Gmail too. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Hidden preheader: the preview line shown in the inbox list. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Seu lugar está reservado. Você é o #{{ number_format($position, 0, ',', '.') }} da lista.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:18px;">

                    {{-- Portal mark + wordmark --}}
                    <tr>
                        <td align="center" style="padding:48px 40px 8px 40px;">
                            <div style="width:72px; height:44px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:38px 38px 0 0;"></div>
                            <div style="width:88px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
                            <div style="margin-top:16px; font-size:15px; letter-spacing:6px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                        </td>
                    </tr>

                    {{-- Headline --}}
                    <tr>
                        <td align="center" style="padding:28px 40px 0 40px;">
                            <h1 style="margin:0; font-size:30px; line-height:1.2; font-weight:normal; color:#F5F0E8;">
                                Você está dentro.<br><span style="color:#C9A84C;">Quase.</span>
                            </h1>
                        </td>
                    </tr>

                    {{-- Position badge --}}
                    <tr>
                        <td align="center" style="padding:24px 40px 0 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="padding:10px 22px; border:1px solid #C9A84C; border-radius:999px; background-color:rgba(201,168,76,0.06);">
                                        <span style="font-size:14px; letter-spacing:1px; color:#F5F0E8; text-transform:uppercase;">
                                            Você é o <span style="color:#C9A84C; font-size:17px; letter-spacing:0;">#{{ number_format($position, 0, ',', '.') }}</span> da lista
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <p style="margin:0 0 20px 0; font-size:17px; line-height:1.6; color:#F5F0E8;">
                                Olá, {{ $name }}.
                            </p>
                            <p style="margin:0 0 20px 0; font-size:16px; line-height:1.75; color:#F5F0E8;">
                                O Limen não abre para todo mundo — e não abre de uma vez. Estamos
                                construindo um <span style="color:#C9A84C;">clube fechado</span> de
                                conteúdo adulto verificado, com curadoria e discrição.
                            </p>
                            <p style="margin:0 0 8px 0; font-size:16px; line-height:1.75; color:#9a938a;">
                                Seu lugar está guardado. Quando abrirmos as portas, quem está na lista
                                entra primeiro — e você será avisado por aqui, sem alarde.
                            </p>
                        </td>
                    </tr>

                    {{-- Referral CTA --}}
                    <tr>
                        <td align="center" style="padding:32px 44px 8px 44px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#C9A84C;">
                                        <a href="{{ $landingUrl }}" style="display:inline-block; padding:14px 34px; font-size:15px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                                            Indique um amigo
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0 0; font-size:13px; line-height:1.6; color:#6f6a62;">
                                Cada indicação aproxima você da abertura.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:36px 44px 44px 44px;">
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
