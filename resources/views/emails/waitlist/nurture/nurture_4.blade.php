{{-- Dia 14 — conexão real (membro) · verificação = poder (performer). --}}
<x-mail.waitlist-nurture
    :title="$subject"
    :preheader="$isPerformer ? 'Não é burocracia. É proteção.' : 'Você sabe disso melhor do que ninguém.'"
    :firstName="$firstName"
    :ctaLabel="$isPerformer ? 'Ver meu painel de fundadora' : 'Acessar meu painel'"
    :ctaUrl="$ctaUrl"
    :unsubscribeUrl="$unsubscribeUrl">
    @if ($isPerformer)
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Quando tudo é verificado, você escolhe com quem se conecta.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">Sem anônimos. Sem ruído. Só quem realmente quer estar ali.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você merece esse nível de controle.</p>
    @else
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">O que existe por aí é ruído. Perfis falsos, intermediários, ilusão.</p>
        <p style="margin:0 0 16px 0; font-size:16px; line-height:1.65; color:#F5F0E8;">O Limen foi construído para quem cansou de fingir que isso é suficiente.</p>
        <p style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">Você está no lugar certo.</p>
    @endif
</x-mail.waitlist-nurture>
