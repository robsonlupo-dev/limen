<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Descadastrar da lista — Limen</title>
</head>
{{-- Standalone page (no Vite/Inertia dependency): reached from an email link by
     guests. Palette matches the landing DNA: fundo #0a0a0a, creme #F5F0E8,
     dourado #C9A84C. The delete happens on the POST below, never on this GET. --}}
<body style="margin:0; padding:0; background-color:#0a0a0a; color:#F5F0E8; font-family:Georgia,'Times New Roman',serif; min-height:100vh;">
    <div style="max-width:480px; margin:0 auto; padding:72px 20px;">
        <div style="text-align:center;">
            <div style="width:64px; height:40px; margin:0 auto; border:2px solid #C9A84C; border-bottom:none; border-radius:34px 34px 0 0;"></div>
            <div style="width:80px; height:2px; margin:0 auto; background-color:#C9A84C;"></div>
            <div style="margin-top:14px; font-size:14px; letter-spacing:6px; color:#C9A84C; text-transform:uppercase;">Limen</div>
        </div>

        <div style="margin-top:44px; text-align:center;">
            <h1 style="margin:0 0 20px 0; font-size:26px; font-weight:normal; line-height:1.3; color:#F5F0E8;">
                Sair da lista de espera?
            </h1>
            <p style="margin:0 0 8px 0; font-size:16px; line-height:1.7; color:#9a938a;">
                Você está prestes a remover
            </p>
            <p style="margin:0 0 32px 0; font-size:16px; color:#C9A84C; word-break:break-all;">
                {{ $email }}
            </p>

            <form method="POST" action="{{ route('waitlist.unsubscribe.confirm') }}" style="margin:0;">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" style="display:inline-block; padding:14px 34px; font-size:15px; letter-spacing:1px; color:#0a0a0a; background-color:#C9A84C; border:none; border-radius:999px; cursor:pointer; font-family:Georgia,serif;">
                    Confirmar descadastro
                </button>
            </form>

            <p style="margin:24px 0 0 0; font-size:14px;">
                <a href="{{ route('landing') }}" style="color:#9a938a; text-decoration:underline;">Não, quero continuar na lista</a>
            </p>
        </div>
    </div>
</body>
</html>
