{{-- PLACEHOLDER COPY — PO substitui. Beat: comunidade / prova social (dia 14). --}}
<x-mail.waitlist-nurture
    title="Uma comunidade selecionada"
    preheader="Quem já está do outro lado da porta."
    :headline="$isPerformer ? 'Você não está sozinha nisso.' : 'Um círculo que está crescendo.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] Criadoras fundadoras estão entrando todos os dias. Você faz parte de um grupo pequeno e selecionado que ajuda a definir como o Limen vai ser.
    @else
        [PLACEHOLDER] Os primeiros membros já estão reservando seus lugares. Chegar cedo significa fazer parte da base que molda a comunidade.
    @endif
</x-mail.waitlist-nurture>
