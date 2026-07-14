{{-- Dia 30 — um mês / paciência. --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Paciência tem recompensa.' : 'Você ainda está aqui.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Acessar meu painel' : 'Ver meu painel de fundador'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Construir algo sólido leva tempo. Você entende isso melhor do que ninguém.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Sua vaga continua reservada. Seu lugar, garantido.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Estamos chegando lá.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Trinta dias desde que você reservou seu lugar.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">A maioria desiste antes disso. Você não.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">O portal está mais perto do que parece.</p>
    @endif
</x-mail.waitlist-nurture>
