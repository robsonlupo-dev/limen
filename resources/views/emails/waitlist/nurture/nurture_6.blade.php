{{-- PLACEHOLDER COPY — PO substitui. Beat: convite / referral (dia 30). O invite
     link NÃO vai no corpo (regra); o CTA aponta ao painel, onde vive a mecânica. --}}
<x-mail.waitlist-nurture
    title="Traga quem você confia"
    preheader="Sua posição pode subir no seu painel."
    :headline="$isPerformer ? 'Convide e suba de nível.' : 'Convide quem merece entrar.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] Cada pessoa que você traz — e que confirma — melhora o seu tier de fundadora. Seu link de convite fica no seu painel, é só compartilhar.
    @else
        [PLACEHOLDER] Indique pessoas que combinam com o Limen. A cada confirmação, seu tier sobe. Pegue seu link de convite direto no seu painel.
    @endif
</x-mail.waitlist-nurture>
