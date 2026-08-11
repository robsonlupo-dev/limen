<!DOCTYPE html>
<html>
<body>
<p>Olá, {{ $user->name }}!</p>
<p>Sua <strong>apresentação de voz</strong> não foi aprovada por não estar de acordo com os Termos de Uso e o Contrato de Performance da Limen.</p>
@if($reason)
<p>Observação da análise: {{ $reason }}</p>
@endif
<p>Você pode gravar uma nova apresentação a qualquer momento — ela passará por uma nova análise antes de aparecer no seu perfil.</p>
<p>Lembre-se: a voz é para despertar curiosidade e mostrar sua personalidade. Não a use para passar contato (telefone, redes sociais) nem para combinar encontro — para conversar, o membro vai até o seu chat.</p>
</body>
</html>
