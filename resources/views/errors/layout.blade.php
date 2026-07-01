<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — Limen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            background-color: #0A0A0B;
            color: #F5F1E8;
            font-family: 'Inter', system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1.5rem;
        }
        .code {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-weight: 600;
            font-size: clamp(4rem, 18vw, 8rem);
            line-height: 1;
            color: #C9A24B;
        }
        .title { font-size: 1.375rem; font-weight: 500; margin-top: 1rem; }
        .desc { color: #9CA3AF; margin-top: 0.5rem; font-weight: 300; }
        .link {
            display: inline-block;
            margin-top: 2rem;
            color: #C9A24B;
            text-decoration: none;
            border: 1px solid rgba(201, 162, 75, 0.4);
            border-radius: 9999px;
            padding: 0.625rem 1.75rem;
            font-size: 0.9375rem;
            transition: background-color 0.2s ease;
        }
        .link:hover { background-color: rgba(201, 162, 75, 0.1); }
    </style>
</head>
<body>
    <div>
        <p class="code">@yield('code')</p>
        <p class="title">@yield('title')</p>
        <p class="desc">@yield('desc')</p>
        <a href="/" class="link">@yield('action', 'Voltar ao início')</a>
    </div>
</body>
</html>
