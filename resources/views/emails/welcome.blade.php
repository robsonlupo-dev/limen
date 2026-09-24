{{-- Carta dos Fundadores — MEMBRO (feat/founders-letter-elite).

     Fala de privacidade e de pertencimento: é o que o membro comprou ao
     entrar. A da performer é outra (`welcome-performer`), sobre ganho e
     autonomia — decisão do PO em 24/09/2026, que substitui a carta única.
     Moldura, marca, saudação e assinatura vivem no componente
     <x-founders-letter>. Sem adjetivo com gênero. A frase do convite (última)
     é travada por teste — mantê-la numa linha só. --}}
<x-founders-letter
    :first-name="$firstName"
    :cta-url="$ctaUrl"
    lede="Poucas portas merecem uma carta. Esta é uma delas."
    cta="Explorar o catálogo"
>
    <tr>
        <td style="padding:24px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Eu sou o Robson. Escrevo junto com o Bruno, com quem fundei o Limen,
                porque a sua chegada não é um cadastro — é o começo de uma relação que
                decidimos cuidar pessoalmente, desde a primeira linha.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                O Limen nasceu de uma recusa: a de aceitar que conexão e discrição
                fossem coisas opostas. Cada detalhe daqui foi construído para que você
                apareça exatamente na medida que escolher — e nunca além dela.
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
                            Aqui, a privacidade não é uma promessa.<br>É a arquitetura.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:34px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Você entra num círculo pequeno por desenho. Não medimos sucesso por
                volume, e sim pela qualidade de cada encontro que acontece aqui dentro.
                Isso pede tempo, critério e presença — dos dois lados.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 52px 0 52px;">
            <p style="margin:0; font-size:17px; line-height:1.75; color:#F5F0E8;">
                Sinta-se em casa.
                Explore o catálogo no seu ritmo e descubra o que preparamos para você.
            </p>
        </td>
    </tr>
</x-founders-letter>
