<x-mail::message>
# Nova denúncia

Uma denúncia foi registrada e aguarda revisão.

- **Denúncia:** #{{ $reportId }}
- **Motivo:** {{ $reason }}
- **Recebida em:** {{ optional($createdAt)->format('d/m/Y H:i') }}
- **Descrição do denunciante:** {{ $hasDetails ? 'sim (ler no painel)' : 'não informada' }}

O alvo, o corpo da denúncia e a identidade de quem denunciou ficam apenas no painel — este e-mail é só o aviso.

<x-mail::button :url="$panelUrl">
Abrir painel de denúncias
</x-mail::button>

Obrigado,<br>
{{ config('app.name') }}
</x-mail::message>
