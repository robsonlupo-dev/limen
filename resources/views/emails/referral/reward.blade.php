<!DOCTYPE html>
<html>
<body>
<p>Olá, {{ $beneficiaryName }}!</p>

@if ($role === 'referrer')
    <p>Boa notícia: <strong>{{ $counterpartyLabel }}</strong> que você indicou se tornou
    ativo(a) no Limen, e por isso você acaba de ganhar
    <strong>{{ $amount }} {{ $amount == 1 ? 'token' : 'tokens' }}</strong> de bônus.</p>
@else
    <p>Seja bem-vindo(a)! Como você se cadastrou por indicação, você acaba de ganhar
    <strong>{{ $amount }} {{ $amount == 1 ? 'token' : 'tokens' }}</strong> de bônus.</p>
@endif

<p>O bônus já está no seu saldo e aparece no seu extrato como
<em>Bônus de indicação</em>. Ele pode ser usado dentro da plataforma.</p>
<p>Bom proveito!</p>
</body>
</html>
