@props(['firstName', 'ctaUrl', 'lede', 'cta'])
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Bem-vindo ao Limen</title>
</head>
{{-- Moldura ÚNICA da Carta dos Fundadores — componente anônimo (feat/founders-letter-elite).

     As duas cartas — a do membro (`emails.welcome`) e a da performer
     (`emails.welcome-performer`) — usam <x-founders-letter> e só preenchem o
     lede, o corpo (slot) e o CTA. Componente, e não @extends, porque um
     mailable com @extends renderizado em teste vaza um output buffer (PHPUnit
     marca o teste como "risky"); componentes de Blade não têm esse efeito. Marca, filetes, eyebrow, saudação, assinatura e rodapé
     vivem aqui, uma vez: é o que impede as duas de divergirem de design.

     Estilo inline apenas (cliente de e-mail descarta <style>). Paleta Limen:
     fundo #0a0a0a, cartão #0d0d0d, creme #F5F0E8, dourado #C9A84C, ouro
     escuro #5c4d22, sépia #9a938a / #6f6a62. Só tabelas e inline — nada de
     flex/grid/float, que Outlook ignora ou quebra (o capitular flutuante da
     1ª versão subia a frase para o topo da letra — daí a abertura ser uma
     linha própria, o "lede").

     SEM IMAGEM, por decisão e por teste: <img> remoto em e-mail é pixel de
     leitura (docs/PIXEL_AUDIT.md, item 5). A marca é desenhada em CSS puro.
     Carta de fundador não rastreia quem a lê.

     O ENVELOPE (assunto, remetente, preheader) é neutro e igual para as duas
     cartas — ver WelcomeFounderEmail e o teste de termos proibidos. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif;">
    {{-- Preheader: o que o cliente mostra na LISTA, ao lado do assunto. É
         envelope, não corpo — vale a mesma regra do assunto. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
        Uma palavra dos fundadores.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;">
        <tr>
            <td align="center" style="padding:56px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto; background-color:#0d0d0d; border:1px solid #262626; border-radius:4px;">

                    {{-- Filete duplo superior: o fio de ouro da maison --}}
                    <tr>
                        <td style="padding:0;">
                            <div style="height:2px; background-color:#C9A84C;"></div>
                            <div style="height:1px; margin-top:3px; background-color:#5c4d22;"></div>
                        </td>
                    </tr>

                    {{-- Marca do portal, em CSS puro, com o nome em versaletes --}}
                    <tr>
                        <td align="center" style="padding:52px 40px 0 40px;">
                            <div style="width:60px; height:38px; margin:0 auto; border:1.5px solid #C9A84C; border-bottom:none; border-radius:32px 32px 0 0;"></div>
                            <div style="width:76px; height:1.5px; margin:0 auto; background-color:#C9A84C;"></div>
                            <div style="margin-top:16px; font-size:13px; letter-spacing:8px; color:#C9A84C; text-transform:uppercase;">Limen</div>
                        </td>
                    </tr>

                    {{-- Eyebrow --}}
                    <tr>
                        <td align="center" style="padding:36px 44px 0 44px;">
                            <div style="font-size:10px; letter-spacing:4px; color:#6f6a62; text-transform:uppercase;">Carta dos Fundadores</div>
                            <div style="width:32px; height:1px; margin:18px auto 0 auto; background-color:#5c4d22;"></div>
                        </td>
                    </tr>

                    {{-- Saudação: primeiro nome só (o nome composto soa a mala direta) --}}
                    <tr>
                        <td style="padding:36px 52px 0 52px;">
                            <p style="margin:0; font-size:18px; line-height:1.5; color:#9a938a; font-style:italic;">Olá, {{ $firstName }}.</p>
                        </td>
                    </tr>

                    {{-- Lede: a abertura numa linha própria, maior --}}
                    <tr>
                        <td style="padding:26px 52px 0 52px;">
                            <p style="margin:0; font-size:22px; line-height:1.45; color:#F5F0E8;">{{ $lede }}</p>
                        </td>
                    </tr>

                    {{-- Corpo da carta (slot) --}}
                    {{ $slot }}

                    {{-- CTA: contorno fino em ouro, não bloco cheio — contenção --}}
                    <tr>
                        <td align="center" style="padding:38px 52px 0 52px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="border:1px solid #C9A84C; border-radius:2px;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block; padding:15px 40px; font-size:13px; letter-spacing:3px; text-transform:uppercase; color:#C9A84C; text-decoration:none; font-family:Georgia,serif;">{{ $cta }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Assinatura --}}
                    <tr>
                        <td style="padding:44px 52px 0 52px;">
                            <p style="margin:0; font-size:15px; line-height:1.6; color:#9a938a;">Com consideração,</p>
                            <p style="margin:14px 0 0 0; font-size:24px; line-height:1.3; color:#F5F0E8; font-style:italic;">Robson &amp; Bruno</p>
                            <p style="margin:6px 0 0 0; font-size:11px; letter-spacing:3px; color:#9a938a; text-transform:uppercase;">Fundadores do Limen</p>
                        </td>
                    </tr>

                    {{-- Rodapé. Sem descadastro: transacional única (uma por conta,
                         disparada por ato da própria pessoa), não marketing. --}}
                    <tr>
                        <td style="padding:40px 52px 0 52px;">
                            <div style="height:1px; background-color:#262626;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 52px 44px 52px;">
                            <p style="margin:0; font-size:11px; letter-spacing:2px; color:#6f6a62; text-transform:uppercase;">Limen &middot; Brasil</p>
                            <p style="margin:8px 0 0 0; font-size:12px; line-height:1.6; color:#6f6a62;">Esta carta é enviada uma única vez.</p>
                        </td>
                    </tr>

                    {{-- Filete duplo inferior --}}
                    <tr>
                        <td style="padding:0;">
                            <div style="height:1px; background-color:#5c4d22;"></div>
                            <div style="height:2px; margin-top:3px; background-color:#C9A84C;"></div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
