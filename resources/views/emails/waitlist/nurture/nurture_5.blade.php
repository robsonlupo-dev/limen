{{-- PLACEHOLDER COPY — PO substitui. Beat: benefícios de fundador (dia 21). --}}
<x-mail.waitlist-nurture
    title="Vantagens de fundador"
    preheader="O que muda por ter chegado antes."
    :headline="$isPerformer ? 'Fundadora tem prioridade.' : 'Fundador entra na frente.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] Como fundadora, você tem prioridade na verificação, destaque no lançamento e condições que não vão se repetir depois da abertura.
    @else
        [PLACEHOLDER] Como fundador, você entra antes de todo mundo e garante vantagens exclusivas que só existem para quem estava aqui desde o começo.
    @endif
</x-mail.waitlist-nurture>
