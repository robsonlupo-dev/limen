<x-mail::message>
# Exclusão cancelada

O pedido para excluir sua conta no Limen foi cancelado. Sua conta continua
ativa e nada foi apagado.

**Não foi você quem cancelou?** Alguém pode ter acesso à sua conta. Troque sua
senha agora e refaça o pedido de exclusão em Configurações.

<x-mail::button :url="$settingsUrl">
Abrir configurações
</x-mail::button>

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
