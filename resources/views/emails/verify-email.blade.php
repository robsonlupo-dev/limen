@php
    $isPerformer = $isPerformer ?? false;
    $w = $welcome ?? null;
    $b = $w['bonuses'] ?? null;
@endphp
@component('mail::message')
<div style="text-align: center; margin-bottom: 32px;">
<svg width="60" height="50" viewBox="0 0 120 100" xmlns="http://www.w3.org/2000/svg">
<path d="M 10 90 L 10 40 Q 10 10 60 10 Q 110 10 110 40 L 110 90" fill="none" stroke="#C9A84C" stroke-width="3"/>
</svg>
<div style="font-family: Georgia, serif; font-size: 22px; letter-spacing: 0.3em; color: #C9A84C; margin-top: 8px; font-weight: 300;">LIMEN</div>
</div>

# {{ $isPerformer ? 'Seu palco está quase pronto.' : 'A porta está entreaberta.' }}

@if ($isPerformer)
Recebemos seu pré-cadastro de criadora. Para concluir e **reservar sua vaga de Criadora Fundadora**, confirme que este e-mail é seu. Leva um clique.
@else
Recebemos seu pré-cadastro. Para concluir e **garantir sua vaga de Membro Fundador**, confirme que este e-mail é seu. Leva um clique.
@endif

@component('mail::button', ['url' => $verificationUrl, 'color' => 'primary'])
Confirmar meu e-mail
@endcomponent

@if ($w && ($w['referral_enabled'] ?? false))
**Depois de confirmar, você libera:**

@if ($isPerformer)
- **O passo a passo da sua verificação** — identidade, idade e perfil, até publicar seu primeiro conteúdo.
- **+{{ $b['performer_first_earning']['referrer'] }} tokens por criadora indicada** que se verifica e faz o 1º ganho. Ela leva +{{ $b['performer_first_earning']['referred'] }}. Um fã que você chamar rende +{{ $b['member_purchase']['referrer'] }}.
- **Você fica com 80%** em conversas, conteúdo, gorjetas e presentes, e 70% em chamadas e lives. Saque por PIX.
@else
- **Seu link de convite.** Quando alguém entra de verdade por ele, você ganha **+{{ $b['member_purchase']['referrer'] }} tokens** e quem você chamou leva **+{{ $b['member_purchase']['referred'] }}**.
- **Bônus extra:** indicou uma criadora que se verifica e faz o 1º ganho? Você leva **+{{ $b['performer_first_earning']['referrer'] }} tokens**.
- **Aviso de lançamento em primeira mão.** Cadastre seu WhatsApp e a gente te chama quando as portas abrirem.
@endif

@component('mail::subcopy')
Os bônus em tokens são crédito de uso no Limen: **não são dinheiro e não podem ser sacados.** Liberados após o lançamento e a conversão da indicação, com janela de segurança de 14 dias, e **valem apenas com e-mail confirmado** — por isso este passo importa.
@endcomponent
@endif

Este link expira em **60 minutos**. Se você não criou uma conta no Limen, pode ignorar este e-mail com segurança.

Com discrição,<br>
**Equipe Limen**

@component('mail::subcopy')
Se o botão não funcionar, copie e cole este link no navegador:<br>
<span style="word-break: break-all;">{{ $verificationUrl }}</span>
@endcomponent
@endcomponent
