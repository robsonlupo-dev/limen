{{-- PLACEHOLDER COPY — PO substitui. Beat: boas-vindas / o que é o Limen (dia 1). --}}
<x-mail.waitlist-nurture
    :title="$isPerformer ? 'Bem-vinda ao Limen' : 'Bem-vindo ao Limen'"
    preheader="Um espaço pensado para quem chega antes de todos."
    :headline="$isPerformer ? 'Você está entre as primeiras.' : 'Que bom ter você aqui.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] O Limen é uma plataforma verificada e discreta para criadoras. Nos próximos dias vamos te contar como funciona por dentro — no seu tempo.
    @else
        [PLACEHOLDER] O Limen é um espaço premium e verificado. Nos próximos dias vamos te mostrar o que estamos construindo e por que vale a pena chegar antes.
    @endif
</x-mail.waitlist-nurture>
