{{-- PLACEHOLDER COPY — PO substitui. Beat: segurança & verificação (dia 3). --}}
<x-mail.waitlist-nurture
    title="Verificação de verdade"
    preheader="Por que o Limen verifica os dois lados."
    :headline="$isPerformer ? 'Um ambiente onde você está protegida.' : 'Todo mundo aqui é verificado.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] No Limen, cada pessoa é verificada e maior de idade. Isso significa menos ruído, menos risco e uma audiência que respeita o seu trabalho.
    @else
        [PLACEHOLDER] Verificamos identidade e idade dos dois lados. Nada de perfis falsos: só pessoas reais, num ambiente pensado para ser seguro e discreto.
    @endif
</x-mail.waitlist-nurture>
