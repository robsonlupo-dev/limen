<x-mail::message>
# Exclusão da sua conta agendada

Recebemos um pedido para excluir permanentemente sua conta no Limen.

- **Data da exclusão:** {{ optional($scheduledAt)->format('d/m/Y') }}
- **Prazo para desistir:** {{ $graceDays }} dias

Até essa data nada é apagado. Você pode cancelar a qualquer momento em
**Configurações → Exclusão de conta**, e sua conta volta ao normal.

Depois da exclusão, seus documentos de verificação, seu perfil e seus dados
pessoais são destruídos e **não há como recuperá-los**. Registros financeiros e
de segurança que a lei nos obriga a guardar permanecem, sem ligação com você.

<x-mail::button :url="$confirmUrl">
Confirmar o pedido
</x-mail::button>

Este link vale por {{ $tokenHours }} horas.

**Não foi você?** Cancele agora em [Configurações]({{ $settingsUrl }}) e troque
sua senha — alguém pode ter acesso à sua conta.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
