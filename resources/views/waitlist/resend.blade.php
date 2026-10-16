<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Reenviar confirmação — Limen</title>
</head>
{{-- Página standalone (sem Vite/Inertia), no mesmo padrão do unsubscribe. O POST
     responde SEMPRE de forma neutra — nunca diz se o e-mail está na lista. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif; min-height:100vh;">
    <div style="max-width:480px; margin:0 auto; padding:72px 20px;">
        <div style="text-align:center;">
            <div style="width:64px; height:40px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:34px 34px 0 0;"></div>
            <div style="width:80px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
            <div style="margin-top:14px; font-size:14px; letter-spacing:6px; color:#C9A84C; text-transform:uppercase;">Limen</div>
        </div>

        <div style="margin-top:44px; text-align:center;">
            <h1 style="margin:0 0 16px 0; font-size:26px; font-weight:normal; line-height:1.3; color:#F5F0E8;">
                Reenviar confirmação
            </h1>
            <p style="margin:0 0 28px 0; font-size:16px; line-height:1.7; color:#9a938a;">
                Inscreveu-se e não recebeu o e-mail? Informe o endereço que você usou — reenviamos o link.
                <br>Confira também a caixa de <span style="color:#C9A84C;">spam</span>.
            </p>

            @if (session('success'))
                <p style="margin:0 0 24px 0; font-size:15px; line-height:1.6; color:#C9A84C;">{{ session('success') }}</p>
            @endif

            <form method="POST" action="{{ route('waitlist.resend') }}" style="margin:0;">
                @csrf
                {{-- Honeypot: invisível a humanos; preenchido = bot. --}}
                <div style="position:absolute; left:-9999px;" aria-hidden="true">
                    <label>Deixe em branco <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <input
                    type="email"
                    name="email"
                    required
                    placeholder="voce@email.com"
                    autocomplete="email"
                    style="width:100%; max-width:320px; padding:13px 16px; font-size:15px; color:#F5F0E8; background-color:#0d0d0d; border:1px solid #2a2a2a; border-radius:12px; margin-bottom:18px;"
                >
                <br>
                <button type="submit" style="display:inline-block; padding:14px 34px; font-size:15px; letter-spacing:1px; color:#0a0a0a; background-color:#C9A84C; border:none; border-radius:999px; cursor:pointer; font-family:Georgia,serif;">
                    Reenviar link
                </button>
            </form>

            <p style="margin:24px 0 0 0; font-size:14px;">
                <a href="{{ route('landing') }}" style="color:#9a938a; text-decoration:underline;">Voltar</a>
            </p>
        </div>
    </div>
</body>
</html>
