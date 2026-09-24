{{-- Carta dos Fundadores — PERFORMER (feat/founders-letter-elite).

     Fala de ganho e de autonomia: para quem trabalha aqui, privacidade é
     pressuposto, não argumento — o que importa é o que ela fatura e o que
     controla. Decisão do PO em 24/09/2026, que substitui a carta única.

     SEM NÚMERO, PORCENTAGEM OU VALOR EM REAIS — decisão do PO: a economia
     (splits, valor do token, mínimo de saque) muda por decisão de negócio, e
     uma cifra numa carta assinada pelos fundadores viraria promessa vencida.
     A carta fala do MECANISMO (a maior parte fica com ela; vira dinheiro por
     PIX), e a fonte dos números segue sendo docs/ECONOMIA.md e a interface.
     Travado por teste (nada de "%" nem "R$" nem dígito no corpo). Só a PRIMEIRA
     mensagem de um membro paga (as seguintes são livres na conversa), por
     isso "a primeira mensagem", não "toda mensagem". A Maison aparece como
     degrau por convite, sem número de vagas (nunca divulgado).

     Moldura e assinatura vivem no componente <x-founders-letter>. Sem adjetivo
     com gênero. A frase do convite (última) é travada por teste. --}}
<x-founders-letter
    :first-name="$firstName"
    :cta-url="$ctaUrl"
    lede="Você não entrou numa plataforma. Entrou num negócio que já é seu."
    cta="Abrir meu painel"
>
    <tr>
        <td style="padding:24px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Eu sou o Robson. Escrevo junto com o Bruno, com quem fundei o Limen,
                porque a sua chegada não é um cadastro — é uma sociedade. Construímos
                o lado difícil do negócio para que você fique com o lado que importa:
                a sua presença.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Aqui, o seu tempo tem preço desde o primeiro contato. A primeira
                mensagem que um membro te envia já é ganho seu — antes de qualquer
                foto, antes de qualquer resposta. Conteúdo, gorjeta, presente,
                conversa, live e chamada: a maior parte do que o membro paga fica
                com você, e o que você ganha vira dinheiro na sua conta, por PIX,
                no seu ritmo.
            </p>
        </td>
    </tr>

    {{-- A frase da casa --}}
    <tr>
        <td align="center" style="padding:34px 52px 0 52px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                <tr>
                    <td style="border-top:1px solid #5c4d22; border-bottom:1px solid #5c4d22; padding:18px 8px;">
                        <p style="margin:0; font-size:17px; line-height:1.6; color:#C9A84C; font-style:italic; text-align:center;">
                            Você decide o que mostra, para quem e por quanto.<br>Nós garantimos que o valor chegue.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:34px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Do outro lado da conversa há apenas membros verificados: ninguém chega
                até você sem antes ter mostrado o rosto para nós. É um círculo pequeno
                por desenho — menos ruído, mais valor em cada encontro.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                E há um degrau acima, ao qual não se candidata: a Maison, por convite
                dos fundadores. Ninguém chega lá pela aparência; chega pela presença.
                Estaremos atentos.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Comece pelo seu painel: preços, conteúdo, visibilidade — tudo é seu para decidir.
            </p>
        </td>
    </tr>
</x-founders-letter>
