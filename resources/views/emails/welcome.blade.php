<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Bem-vindo ao Limen</title>
</head>
{{-- Estilo inline apenas (cliente de e-mail descarta <style>). Paleta Limen:
     fundo #0a0a0a, creme #F5F0E8, dourado #C9A84C. Mesmo padrão do e-mail da
     waitlist.

     SEM IMAGEM: a marca do portal é desenhada com bordas CSS, como no
     confirmation.blade.php. Não é só peso — <img> remoto num e-mail é pixel de
     leitura (diz quando e onde a pessoa abriu), e o header do vendor já teve um
     caso desses removido na auditoria de 20/07 (docs/PIXEL_AUDIT.md, item 5).
     Carta de fundador não rastreia quem a leu. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Preheader: o trecho que o cliente mostra na LISTA, ao lado do assunto.
         É envelope, não corpo — vale a mesma regra do assunto, e por isso não
         diz o que a plataforma é. Ver WelcomeFounderEmail. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Uma palavra dos fundadores.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:18px;">

                    {{-- Marca do portal, em CSS puro --}}
                    <tr>
                        <td align="center" style="padding:44px 40px 8px 40px;">
                            <div style="width:68px; height:42px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:36px 36px 0 0;"></div>
                            <div style="width:84px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
                            <div style="margin-top:14px; font-size:14px; letter-spacing:6px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <p style="margin:0; font-size:17px; line-height:1.5; color:#9a938a;">Olá, {{ $firstName }}.</p>
                        </td>
                    </tr>

                    {{-- Parágrafo 1 — quem escreve --}}
                    <tr>
                        <td style="padding:22px 44px 0 44px;">
                            <p style="margin:0; font-size:16px; line-height:1.7; color:#F5F0E8;">
                                Eu sou o Robson, fundador do Limen, e junto com o Bruno quero te dar as
                                boas-vindas pessoalmente.
                            </p>
                        </td>
                    </tr>

                    {{-- Parágrafo 2 — o que esperar --}}
                    <tr>
                        <td style="padding:18px 44px 0 44px;">
                            <p style="margin:0; font-size:16px; line-height:1.7; color:#F5F0E8;">
                                O Limen é um espaço premium de conexão e descoberta. Aqui, sua privacidade
                                é o produto, não um detalhe.
                            </p>
                        </td>
                    </tr>

                    {{-- Parágrafo 3 — próximo passo. Mesmo texto para membro e
                         performer (decisão do PO): a carta é dos fundadores,
                         não um onboarding por papel. --}}
                    <tr>
                        <td style="padding:18px 44px 0 44px;">
                            <p style="margin:0; font-size:16px; line-height:1.7; color:#F5F0E8;">
                                Explore o catálogo e descubra o que preparamos para você.
                            </p>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding:32px 44px 0 44px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#C9A84C;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block; padding:15px 44px; font-size:16px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                                            Explorar o catálogo
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Assinatura --}}
                    <tr>
                        <td style="padding:34px 44px 0 44px;">
                            <div style="border-top:1px solid #262626; padding-top:24px;">
                                <p style="margin:0; font-size:16px; line-height:1.6; color:#F5F0E8;">
                                    Robson &amp; Bruno
                                </p>
                                <p style="margin:4px 0 0 0; font-size:13px; letter-spacing:1px; color:#9a938a;">
                                    Fundadores do Limen
                                </p>
                            </div>
                        </td>
                    </tr>

                    {{-- Rodapé. Sem link de descadastro: esta é uma mensagem
                         transacional única (uma por conta, disparada por um ato
                         da própria pessoa), não comunicação de marketing — o
                         opt-out da waitlist existe porque AQUELA é uma régua de
                         nutrição. Se um dia a carta virar campanha, o
                         descadastro entra junto. --}}
                    <tr>
                        <td style="padding:30px 44px 44px 44px;">
                            <p style="margin:0; font-size:12px; letter-spacing:1px; color:#6f6a62;">
                                Limen · Brasil
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
