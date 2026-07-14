@props([
    'title',
    'preheader',
    'firstName',
    'ctaLabel',
    'ctaUrl',
    'unsubscribeUrl',
])
{{-- Message layout shared by every nurturing step: greeting → body (the slot:
     up to 3 short paragraphs) → a single gold CTA button. Wraps the limen-shell
     (portal mark + footer/unsubscribe). Each step blade supplies its
     title/preheader, the paragraph copy, and the CTA label/URL, so the
     structure never drifts between steps. The CTA always points at the founder
     panel (/f/{code}) with a role/day UTM — never a direct invite link (Limen
     discretion rule); the referral mechanic lives only on the panel. --}}
<x-mail.limen-shell :title="$title" :preheader="$preheader" :unsubscribeUrl="$unsubscribeUrl">
    <tr>
        <td align="center" style="padding:30px 40px 0 40px;">
            <p style="margin:0; font-size:17px; line-height:1.5; color:#9a938a;">Olá, {{ $firstName }}.</p>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:22px 44px 0 44px;">
            {{ $slot }}
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:32px 44px 0 44px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                <tr>
                    <td style="border-radius:999px; background-color:#C9A84C;">
                        <a href="{{ $ctaUrl }}" style="display:inline-block; padding:15px 44px; font-size:16px; letter-spacing:1px; color:#0a0a0a; text-decoration:none; font-family:Georgia,serif;">
                            {{ $ctaLabel }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</x-mail.limen-shell>
