<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Limen') }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="description" content="Conteúdo adulto premium. Performers verificados. Privacidade total.">
    <meta name="theme-color" content="#0A0A0A">
    <meta property="og:title" content="Limen — O Portal">
    <meta property="og:description" content="Conteúdo adulto premium. Performers verificados. Privacidade total.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-background text-cream antialiased">
    @inertia
</body>
</html>
