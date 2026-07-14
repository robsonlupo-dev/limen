{{-- PLACEHOLDER COPY — PO substitui. Beat: como funciona / bastidores (dia 7). --}}
<x-mail.waitlist-nurture
    title="Como o Limen funciona"
    preheader="Uma olhada por dentro da plataforma."
    :headline="$isPerformer ? 'Feito para quem cria.' : 'Feito para quem valoriza.'"
    :firstName="$firstName" :isPerformer="$isPerformer" :panelUrl="$panelUrl" :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        [PLACEHOLDER] Tokens, gorjetas e sessões privadas — com um split transparente por nível. Aqui você tem controle sobre o que oferece e para quem.
    @else
        [PLACEHOLDER] Você usa tokens para apoiar quem você curte: gorjetas e momentos privados, do seu jeito. Simples, direto e sem pegadinha.
    @endif
</x-mail.waitlist-nurture>
