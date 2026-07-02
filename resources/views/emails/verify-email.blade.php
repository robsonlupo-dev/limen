@component('mail::message')
<div style="text-align: center; margin-bottom: 32px;">
<svg width="60" height="50" viewBox="0 0 120 100" xmlns="http://www.w3.org/2000/svg">
<path d="M 10 90 L 10 40 Q 10 10 60 10 Q 110 10 110 40 L 110 90" fill="none" stroke="#C9A84C" stroke-width="3"/>
</svg>
<div style="font-family: Georgia, serif; font-size: 22px; letter-spacing: 0.3em; color: #C9A84C; margin-top: 8px; font-weight: 300;">LIMEN</div>
</div>

# Confirme seu e-mail

Olá! Bem-vindo(a) ao Portal.

Para ativar sua conta e ter acesso completo, clique no botão abaixo para confirmar seu endereço de e-mail.

@component('mail::button', ['url' => $verificationUrl, 'color' => 'primary'])
Confirmar meu e-mail
@endcomponent

Este link expira em **60 minutos**. Se você não criou uma conta no Limen, pode ignorar este e-mail com segurança.

Com discrição,<br>
**Equipe Limen**

@component('mail::subcopy')
Se o botão não funcionar, copie e cole este link no navegador:<br>
<span style="word-break: break-all;">{{ $verificationUrl }}</span>
@endcomponent
@endcomponent
