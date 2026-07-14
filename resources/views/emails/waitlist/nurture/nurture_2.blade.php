{{-- Dia 3 — silêncio/vantagem (membro) · quem cria merece mais (performer). --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Você já sabe disso.' : 'Nem tudo precisa ser anunciado.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Ver meu painel' : 'Ver meu painel de fundador'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você investe tempo, presença, energia.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">O que você recebe em troca deveria refletir isso.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Estamos construindo um espaço onde isso finalmente é verdade.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Enquanto outros falam sobre o que vão fazer, nós construímos.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você está do lado de dentro de algo que ainda não existe para o mundo.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Isso tem um nome: vantagem.</p>
    @endif
</x-mail.waitlist-nurture>
