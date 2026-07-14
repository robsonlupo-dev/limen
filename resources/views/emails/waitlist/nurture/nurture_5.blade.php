{{-- Dia 21 — indicação/quem você traz. CTA aponta ao painel (referral vive lá). --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Seu nome abre portas aqui.' : 'Seu círculo diz muito sobre você.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Trazer alguém da minha confiança' : 'Trazer alguém'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Se você conhece alguém que deveria estar no Limen, traga.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Performers que chegam por indicação chegam com contexto — e isso muda tudo.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Seu nome vale aqui.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Cada pessoa que você traz carrega o seu nome.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Quem entra por você chega com uma expectativa diferente — e isso importa.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Compartilhe com quem merece estar aqui.</p>
    @endif
</x-mail.waitlist-nurture>
