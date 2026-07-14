{{-- Dia 45 — antecipação da abertura. --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Você vai ser das primeiras.' : 'Algumas coisas não se explicam — se experimentam.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Ver meu painel de fundadora' : 'Acessar meu painel'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Não sabemos o dia exato. Mas sabemos quem estará na frente.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você já está lá.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Prepare-se.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Não vamos descrever o que você vai encontrar aqui.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Palavras não fazem jus.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Prepare-se.</p>
    @endif
</x-mail.waitlist-nurture>
