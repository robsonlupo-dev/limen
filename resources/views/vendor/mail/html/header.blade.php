@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- O default do Laravel trocava o nome por um <img> hospedado em laravel.com
     quando o slot era exatamente "Laravel". Imagem remota em e-mail é pixel de
     rastreio na prática: quem hospeda vê IP, cliente de e-mail e hora da
     abertura. Ramo morto em produção (APP_NAME=Limen), mas o .env.example ainda
     traz APP_NAME=Laravel — bastava um ambiente com o default. Removido. --}}
{!! $slot !!}
</a>
</td>
</tr>
