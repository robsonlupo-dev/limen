<!DOCTYPE html>
<html>
<body>
<p>Olá, {{ $user->name }}!</p>
<p>Infelizmente sua verificação de identidade foi <strong>recusada</strong>.</p>
@if($reason)
<p>Motivo: {{ $reason }}</p>
@endif
<p>Você pode resubmeter os documentos após corrigir o problema.</p>
</body>
</html>
