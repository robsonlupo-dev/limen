<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        SEO / social meta rendered SERVER-SIDE from the page's `meta` prop.
        Inertia SSR is off, so tags set via the client `<Head>` are invisible to
        social scrapers (WhatsApp/Telegram/Facebook/X don't run JS). Rendering
        here — on the initial HTML the scraper fetches — is what makes previews
        work. Controllers pass a `meta` array; anything omitted falls back below.
    --}}
    @php
        $meta = ($page['props']['meta'] ?? []);
        $metaTitle = $meta['title'] ?? config('app.name', 'Limen');
        $metaDescription = $meta['description'] ?? 'Conteúdo adulto premium. Performers verificados. Privacidade total.';
        $ogTitle = $meta['og_title'] ?? $metaTitle;
        $ogDescription = $meta['og_description'] ?? $metaDescription;
        $ogType = $meta['og_type'] ?? 'website';
        $ogUrl = $meta['og_url'] ?? url()->current();
        $ogImage = $meta['og_image'] ?? url('/og-image.png');
        $canonical = $meta['canonical'] ?? null;
    @endphp
    <title inertia>{{ $metaTitle }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="theme-color" content="#0A0A0A">
    @if ($canonical)<link rel="canonical" href="{{ $canonical }}">@endif
    <meta property="og:site_name" content="Limen">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:url" content="{{ $ogUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    {{-- Fontes: self-hosted em public/fonts, declaradas em resources/css/fonts.css
         e carregadas pelo @vite abaixo. Nada de CDN aqui — esta view é a raiz de
         toda página Inertia, então um <link> externo viraria requisição a
         terceiro em cada tela logada. Ver docs/PIXEL_AUDIT.md. --}}
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-background text-cream antialiased">
    @inertia
</body>
</html>
