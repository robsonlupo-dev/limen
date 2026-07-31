@component('mail::message')
<div style="text-align: center; margin-bottom: 32px;">
<svg width="60" height="50" viewBox="0 0 120 100" xmlns="http://www.w3.org/2000/svg">
<path d="M 10 90 L 10 40 Q 10 10 60 10 Q 110 10 110 40 L 110 90" fill="none" stroke="#C9A84C" stroke-width="3"/>
</svg>
<div style="font-family: Georgia, serif; font-size: 22px; letter-spacing: 0.3em; color: #C9A84C; margin-top: 8px; font-weight: 300;">LIMEN</div>
</div>

# Seu código de acesso

Use o código abaixo para entrar. Ele expira em **{{ $minutes }} minutos**.

<div style="text-align: center; margin: 32px 0;">
<span style="display: inline-block; font-family: 'Courier New', monospace; font-size: 40px; letter-spacing: 0.35em; color: #1a1a1a; background: #f5f0e6; border: 1px solid #C9A84C; border-radius: 12px; padding: 18px 28px; font-weight: 600;">{{ $code }}</span>
</div>

Se não foi você que pediu, ignore este e-mail — nenhuma ação é tomada sem o código.

Com discrição,<br>
**Equipe Limen**

@component('mail::subcopy')
Por segurança, nunca compartilhe este código. Ninguém da equipe vai pedi-lo.
@endcomponent
@endcomponent
