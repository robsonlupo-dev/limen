@component('mail::message')
<div style="text-align: center; margin-bottom: 32px;">
<svg width="60" height="50" viewBox="0 0 120 100" xmlns="http://www.w3.org/2000/svg">
<path d="M 10 90 L 10 40 Q 10 10 60 10 Q 110 10 110 40 L 110 90" fill="none" stroke="#C9A84C" stroke-width="3"/>
</svg>
<div style="font-family: Georgia, serif; font-size: 22px; letter-spacing: 0.3em; color: #C9A84C; margin-top: 8px; font-weight: 300;">LIMEN</div>
</div>

# Redefinição de senha

Recebemos um pedido para redefinir a senha da sua conta no Limen.

Clique no botão abaixo para escolher uma nova senha.

@component('mail::button', ['url' => $resetUrl, 'color' => 'primary'])
Redefinir minha senha
@endcomponent

Este link expira em **{{ $count }} minutos**. Se não foi você que solicitou, ignore este e-mail — sua senha continuará a mesma.

Com discrição,<br>
**Equipe Limen**

@component('mail::subcopy')
Se o botão não funcionar, copie e cole este link no navegador:<br>
<span style="word-break: break-all;">{{ $resetUrl }}</span>
@endcomponent
@endcomponent
