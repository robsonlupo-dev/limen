@php
    $isPerformer = $isPerformer ?? false;
    $w = $welcome ?? null;
    $b = $w['bonuses'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>{{ $isPerformer ? 'Reserve sua vaga' : 'Cruze a porta' }} — Limen</title>
</head>
{{-- Redesign maison (feat/growth-entries, pedido do PO 04/11): saiu do tema
     markdown padrão do Laravel para o MESMO vestido escuro dos e-mails da
     waitlist. Inline styles only (clientes de e-mail removem <style>). Paleta:
     fundo #0a0a0a, creme #F5F0E8, dourado #C9A84C. A marca do portal é desenhada
     com bordas CSS — SEM <img>/<svg>/data-URI, de propósito: <img> remoto em
     e-mail é pixel de leitura (docs/PIXEL_AUDIT.md) e o Limen não rastreia
     quando/onde o e-mail foi aberto. Moldura dupla (borda externa + filete
     dourado interno) e ornamento tipográfico ✦ fazem o "elitizado" sem imagem. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Preheader oculto (preview da caixa de entrada). --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $isPerformer ? 'Um clique separa você da sua vaga de Criadora Fundadora.' : 'Um clique separa você do outro lado da porta.' }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:18px;">
                    <tr>
                        <td style="padding:10px;">
                            {{-- Filete interno: a moldura dupla da casa. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid rgba(201,168,76,0.28); border-radius:12px;">

                                {{-- Marca do portal --}}
                                <tr>
                                    <td align="center" style="padding:46px 40px 0 40px;">
                                        <div style="width:76px; height:48px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:40px 40px 0 0;"></div>
                                        <div style="width:94px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
                                        <div style="margin-top:16px; font-size:15px; letter-spacing:7px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                                        <div style="margin-top:7px; font-size:10px; letter-spacing:4px; color:#6f6a62; text-transform:uppercase;">{{ $isPerformer ? 'Criadoras Fundadoras' : 'Founding Members' }}</div>
                                    </td>
                                </tr>

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

                                {{-- Headline --}}
                                <tr>
                                    <td align="center" style="padding:24px 44px 0 44px;">
                                        <h1 style="margin:0; font-size:30px; line-height:1.3; font-weight:normal; color:#F5F0E8;">
                                            {{ $isPerformer ? 'Seu palco está quase pronto.' : 'A porta está entreaberta.' }}
                                        </h1>
                                    </td>
                                </tr>

                                {{-- Body --}}
                                <tr>
                                    <td align="center" style="padding:20px 48px 0 48px;">
                                        <p style="margin:0; font-size:16px; line-height:1.7; color:#c7c0b6;">
                                            @if ($isPerformer)
                                                Recebemos seu pré-cadastro de criadora. Para concluir e <span style="color:#F5F0E8;">reservar sua vaga de Criadora Fundadora</span>, confirme que este e-mail é seu. Leva um clique.
                                            @else
                                                Recebemos seu pré-cadastro. Para concluir e <span style="color:#F5F0E8;">garantir sua vaga de Membro Fundador</span>, confirme que este e-mail é seu. Leva um clique.
                                            @endif
                                        </p>
                                    </td>
                                </tr>

                                {{-- CTA --}}
                                <tr>
                                    <td align="center" style="padding:32px 44px 0 44px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                            <tr>
                                                <td style="border-radius:999px; background-color:#C9A84C;">
                                                    <a href="{{ $verificationUrl }}" style="display:inline-block; padding:15px 46px; font-size:16px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                                                        Confirmar meu e-mail
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:14px 0 0 0; font-size:12px; color:#6f6a62;">O link expira em 60 minutos.</p>
                                    </td>
                                </tr>

                                @if ($w && ($w['referral_enabled'] ?? false))
                                {{-- O que a confirmação libera — linhas emolduradas, não bullets. --}}
                                <tr>
                                    <td style="padding:36px 40px 0 40px;">
                                        <p style="margin:0 0 14px 0; font-size:11px; letter-spacing:3px; color:#C9A84C; text-transform:uppercase; text-align:center;">Depois de confirmar, você libera</p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #262626; border-radius:12px;">
                                            @if ($isPerformer)
                                            <tr>
                                                <td style="padding:16px 20px; border-bottom:1px solid #1d1d1d;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">O passo a passo da sua verificação</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Identidade, idade e perfil — até publicar seu primeiro conteúdo.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 20px; border-bottom:1px solid #1d1d1d;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">+{{ $b['performer_first_earning']['referrer'] }} tokens por criadora indicada</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Quando ela se verifica e faz o 1º ganho — ela leva +{{ $b['performer_first_earning']['referred'] }}. Um fã que você chamar rende +{{ $b['member_purchase']['referrer'] }}.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 20px;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">Você fica com 80%</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Em conversas, conteúdo, gorjetas e presentes — e 70% em chamadas e lives. Saque por PIX.</p>
                                                </td>
                                            </tr>
                                            @else
                                            <tr>
                                                <td style="padding:16px 20px; border-bottom:1px solid #1d1d1d;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">Seu link de convite</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Quando alguém entra de verdade por ele, você ganha +{{ $b['member_purchase']['referrer'] }} tokens e quem você chamou leva +{{ $b['member_purchase']['referred'] }}.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 20px; border-bottom:1px solid #1d1d1d;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">Bônus extra de criadora</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Indicou uma criadora que se verifica e faz o 1º ganho? Você leva +{{ $b['performer_first_earning']['referrer'] }} tokens.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:16px 20px;">
                                                    <p style="margin:0; font-size:14px; line-height:1.6; color:#F5F0E8;">Aviso de lançamento em primeira mão</p>
                                                    <p style="margin:3px 0 0 0; font-size:13px; line-height:1.6; color:#9a938a;">Cadastre seu WhatsApp e a gente te chama quando as portas abrirem.</p>
                                                </td>
                                            </tr>
                                            @endif
                                        </table>
                                        <p style="margin:14px 2px 0 2px; font-size:12px; line-height:1.7; color:#6f6a62;">
                                            Os bônus em tokens são crédito de uso no Limen: <span style="color:#9a938a;">não são dinheiro e não podem ser sacados.</span> Liberados após o lançamento e a conversão da indicação, com janela de segurança de 14 dias, e valem apenas com e-mail confirmado — por isso este passo importa.
                                        </p>
                                    </td>
                                </tr>
                                @endif

                                {{-- Assinatura + fallback --}}
                                <tr>
                                    <td style="padding:34px 44px 44px 44px;">
                                        <div style="border-top:1px solid #262626; padding-top:24px; text-align:center;">
                                            <p style="margin:0; font-size:14px; line-height:1.7; color:#9a938a;">Se você não criou uma conta no Limen, pode ignorar este e-mail com segurança.</p>
                                            <p style="margin:16px 0 0 0; font-size:14px; color:#F5F0E8;">Com discrição,<br><span style="color:#C9A84C;">Equipe Limen</span></p>
                                            <p style="margin:22px 0 0 0; font-size:11px; line-height:1.6; color:#6f6a62;">
                                                Se o botão não funcionar, copie e cole este link no navegador:<br>
                                                <span style="word-break:break-all;"><a href="{{ $verificationUrl }}" style="color:#9a938a; text-decoration:underline;">{{ $verificationUrl }}</a></span>
                                            </p>
                                        </div>
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>
                </table>
                <p style="margin:18px 0 0 0; font-size:11px; letter-spacing:2px; color:#4d4942;">LIMEN · BRASIL · +18</p>
            </td>
        </tr>
    </table>
</body>
</html>
