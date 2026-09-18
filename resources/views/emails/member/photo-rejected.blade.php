<!DOCTYPE html>
<html>
<body>
<p>Olá, {{ $user->name }}!</p>
<p>Uma <strong>foto do seu perfil</strong> não foi aprovada por não estar de acordo com os Termos de Uso da Limen.</p>
@if($reason)
<p>Observação da análise: {{ $reason }}</p>
@endif
<p>Você pode enviar outra foto a qualquer momento — ela passará por uma nova análise antes de aparecer no seu perfil.</p>
<p>Lembre-se: envie apenas fotos suas. Não use imagens de terceiros nem fotos que não sejam do seu rosto/pessoa; isso protege você e a comunidade.</p>
</body>
</html>
