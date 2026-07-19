<x-mail::message>
# Payout aguardando revisão manual

Um saque foi estacionado e precisa da sua decisão. Nenhum token foi movido — a reserva continua de pé.

**Motivo:** {{ $reason }}

- **Payout:** #{{ $payoutId }}
- **Performer:** #{{ $performerId }}
- **Valor:** R$ {{ $amountBrl }}
- **Tokens:** {{ $tokens }}
- **Solicitado em:** {{ optional($createdAt)->format('d/m/Y H:i') }}

Reprocessar o saque o recoloca na fila de reconciliação; se preferir, resolva manualmente com o provedor antes de reprocessar.

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
