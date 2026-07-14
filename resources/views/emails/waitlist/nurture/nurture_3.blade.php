{{-- Dia 7 — posição na fila (membro) · discrição por design (performer). --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'É o padrão mínimo.' : 'Seu número está guardado.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Acessar meu painel' : 'Confirmar meu lugar'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">No Limen, sua identidade é protegida por design — não por promessa.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Quem acessa seu conteúdo foi verificado. Quem paga, paga de verdade.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Sem surpresas. Sem exposição indesejada.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Seu lugar na fila não se move. Não expira. Não é transferível.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Enquanto o portal permanece fechado, sua posição cresce em valor.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Quando as portas abrirem, você entra primeiro.</p>
    @endif
</x-mail.waitlist-nurture>
