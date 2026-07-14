{{-- Dia 1 — reserva garantida / boas-vindas. --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Isso é só o começo.' : 'Guarde este email.'"
    :firstName="$firstName"
    :ctaLabel="'Acessar meu painel'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você deu o primeiro passo para algo diferente de tudo que existe no Brasil.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Sua reserva está ativa. Seu número, guardado.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Quando as portas abrirem, você sabe onde encontrar a gente.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Seu lugar está reservado.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Não por sorte. Por escolha.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">O que estamos construindo não é para todo mundo — e você já sabe disso.</p>
    @endif
</x-mail.waitlist-nurture>
