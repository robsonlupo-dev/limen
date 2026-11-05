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
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:18px;">
                 <tr>
                  <td style="padding:10px;">
                   {{-- Filete dourado interno: moldura dupla, o "elitizado" sem
                        imagem (o redesign de 04/11; <img> remoto segue banido —
                        pixel de leitura, docs/PIXEL_AUDIT.md). --}}
                   <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid rgba(201,168,76,0.28); border-radius:12px;">

                    {{-- Cabeçalho: foto da porta EMBUTIDA (CID) — nunca <img> remoto
                         (pixel de leitura, PIXEL_AUDIT). Sem $message (render de teste/
                         preview), o arco CSS assume. --}}
                    @isset($message)
                    <tr>
                        <td style="padding:0; line-height:0;">
                            <img src="{{ $message->embed(public_path('email/hero-porta.jpg')) }}" width="538" alt="" style="display:block; width:100%; height:auto; border-radius:11px 11px 0 0;">
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:30px 40px 0 40px;">
                            <div style="font-size:15px; letter-spacing:7px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                            <div style="margin-top:7px; font-size:10px; letter-spacing:4px; color:#6f6a62; text-transform:uppercase;">Founding Members</div>
                        </td>
                    </tr>
                    @else
                    {{-- Portal mark (fallback sem $message) --}}
                    <tr>
                        <td align="center" style="padding:46px 40px 0 40px;">
                            <div style="width:76px; height:48px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:40px 40px 0 0;"></div>
                            <div style="width:94px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
                            <div style="margin-top:16px; font-size:15px; letter-spacing:7px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                            <div style="margin-top:7px; font-size:10px; letter-spacing:4px; color:#6f6a62; text-transform:uppercase;">Founding Members</div>
                        </td>
                    </tr>
                    @endisset

                    {{-- Ornamento --}}
                    <tr>
                        <td align="center" style="padding:26px 60px 0 60px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-top:1px solid #262626;"></td>
                                    <td width="40" align="center" style="font-size:11px; color:#C9A84C; padding:0 2px; line-height:0;">✦</td>
                                    <td style="border-top:1px solid #262626;"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td align="center" style="padding:30px 40px 0 40px;">
                            <p style="margin:0; font-size:17px; line-height:1.5; color:#9a938a;">Olá, {{ $firstName }}.</p>
                        </td>
                    </tr>

                    @if (!empty($isReminder))
                    {{-- Lembrete de 24h: mesmo e-mail, aviso no topo de que ainda falta confirmar. --}}
                    <tr>
                        <td align="center" style="padding:16px 40px 0 40px;">
                            <p style="margin:0; font-size:15px; line-height:1.6; color:#C9A84C;">
                                Você entrou na lista, mas ainda não confirmou seu e-mail. Falta só um clique —
                                e, se não achar nossa primeira mensagem, confira a caixa de spam.
                            </p>
                        </td>
                    </tr>
                    @endif

                    {{-- Headline --}}
                    <tr>
                        <td align="center" style="padding:10px 40px 0 40px;">
                            <h1 style="margin:0; font-size:30px; line-height:1.3; font-weight:normal; color:#F5F0E8;">
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
                                    O Limen é uma plataforma verificada e exclusiva. Você faz parte do grupo fundador do Limen. Seu espaço está guardado até a abertura.
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
                                    {{ $isPerformer ? 'Acessar meu painel de fundador(a)' : 'Acessar meu painel de fundador' }}
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
            </td>
        </tr>
    </table>
</body>
</html>
