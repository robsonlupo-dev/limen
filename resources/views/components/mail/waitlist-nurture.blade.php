@props([
    'title',
    'preheader',
    'headline',
    'firstName',
    'isPerformer',
    'panelUrl',
    'unsubscribeUrl',
])
{{-- Message layout shared by every nurturing step: greeting → headline → body
     (the slot) → discreet panel link. Wraps the limen-shell (portal mark +
     footer/unsubscribe). Each step blade only supplies its title/preheader/
     headline and the body copy, so the structure never drifts between steps. --}}
<x-mail.limen-shell :title="$title" :preheader="$preheader" :unsubscribeUrl="$unsubscribeUrl">
    <tr>
        <td align="center" style="padding:30px 40px 0 40px;">
            <p style="margin:0; font-size:17px; line-height:1.5; color:#9a938a;">Olá, {{ $firstName }}.</p>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:10px 40px 0 40px;">
            <h1 style="margin:0; font-size:26px; line-height:1.3; font-weight:normal; color:#F5F0E8;">
                {{ $headline }}
            </h1>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:26px 44px 0 44px;">
            <div style="margin:0; font-size:16px; line-height:1.65; color:#F5F0E8;">
                {{ $slot }}
            </div>
        </td>
    </tr>
    <tr>
        <td style="padding:34px 44px 0 44px;">
            <div style="border-top:1px solid #262626; padding-top:24px; text-align:center;">
                <a href="{{ $panelUrl }}" style="font-size:14px; color:#9a938a; text-decoration:underline;">
                    {{ $isPerformer ? 'Acessar meu painel de fundadora' : 'Acessar meu painel de fundador' }}
                </a>
            </div>
        </td>
    </tr>
</x-mail.limen-shell>
