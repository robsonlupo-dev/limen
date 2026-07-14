{{-- PLACEHOLDER COPY — PO substitui. Beat: abertura se aproximando (dia 45). --}}
<x-mail.waitlist-nurture
    title="A abertura está chegando"
    preheader="Falta pouco para as portas abrirem."
    :headline="$isPerformer ? 'Seu espaço está guardado.' : 'Seu lugar continua reservado.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] Estamos nos preparando para abrir. Quando chegar a hora, você será uma das primeiras a entrar — sem fila, sem espera.
    @else
        [PLACEHOLDER] Falta pouco. Quando o Limen abrir, você entra antes de todos. Fique de olho: o próximo e-mail pode ser o convite de entrada.
    @endif
</x-mail.waitlist-nurture>
