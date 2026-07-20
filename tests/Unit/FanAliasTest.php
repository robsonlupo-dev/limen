<?php

use App\Support\FanAlias;
use Tests\TestCase;

// tests/Unit não é ligado ao TestCase pelo Pest.php (só Feature é), e o FanAlias
// lê config('app.key') — precisa da app de pé. Sem banco: nada aqui persiste.
uses(TestCase::class);

/**
 * Pseudônimo do membro nas telas da performer (App\Support\FanAlias).
 *
 * O que estes testes protegem é a propriedade que fecha a dívida registrada em
 * docs/SECURITY_ISSUES.md: o rótulo que a performer vê não pode ser função
 * pública do id do membro, e não pode ser o mesmo entre performers.
 *
 * Sobre as asserções de "são diferentes": o espaço de exibição tem 10.000
 * valores, então dois pares QUALQUER colidem com probabilidade 1/10.000. Um
 * teste de par único seria flaky uma vez a cada dez mil execuções, e piscar sem
 * bug é como uma suíte deixa de ser lida. Por isso as propriedades estatísticas
 * abaixo são medidas sobre muitos pares, com uma tolerância que só um alias
 * quebrado de verdade estoura.
 */
it('é determinístico: o mesmo par sempre dá o mesmo alias', function () {
    expect(FanAlias::for(7, 42))->toBe(FanAlias::for(7, 42));
    expect(FanAlias::handle(7, 42))->toBe(FanAlias::handle(7, 42));
});

it('fica na faixa de 4 dígitos', function () {
    foreach (range(1, 200) as $memberId) {
        expect(FanAlias::for(1, $memberId))->toBeGreaterThanOrEqual(0)->toBeLessThan(10000);
    }
});

it('formata o rótulo com zeros à esquerda', function () {
    $label = FanAlias::label(7, 42);

    expect($label)->toStartWith('Fã #');
    expect(substr($label, strlen('Fã #')))->toHaveLength(4);
    expect(FanAlias::label(7, 42, 'Membro #'))->toBe(
        'Membro #'.substr($label, strlen('Fã #'))
    );
});

it('dá aliases diferentes para membros diferentes na mesma performer', function () {
    // Quantos dos 500 membros caem no MESMO valor do membro #1. Um alias sadio
    // espalha (esperado ~0,05); um alias quebrado (ex.: constante, ou ignorando
    // o membro) daria 500.
    $reference = FanAlias::for(1, 1);
    $colisoes = collect(range(2, 501))->filter(fn ($id) => FanAlias::for(1, $id) === $reference)->count();

    expect($colisoes)->toBeLessThan(5);
});

it('dá aliases diferentes para a mesma pessoa em performers diferentes', function () {
    // É esta a propriedade que impede correlacionar o mesmo membro entre dois
    // perfis de performer — o cerne da mitigação.
    $iguais = collect(range(1, 500))
        ->filter(fn ($performerId) => FanAlias::for($performerId, 99) === FanAlias::for($performerId + 1, 99))
        ->count();

    expect($iguais)->toBeLessThan(5);
});

it('não devolve o id do membro como alias', function () {
    // A regressão concreta: enquanto o rótulo era `id % 10000`, todo membro com
    // id < 10000 tinha alias == id. Aqui isso só pode acontecer por acaso.
    $vazamentos = collect(range(1, 500))
        ->filter(fn ($memberId) => FanAlias::for(1, $memberId) === $memberId)
        ->count();

    expect($vazamentos)->toBeLessThan(5);
});

it('não reproduz a fórmula antiga (id % 10000)', function () {
    $iguaisAoLegado = collect(range(1, 500))
        ->filter(fn ($memberId) => FanAlias::for(1, $memberId) === $memberId % 10000)
        ->count();

    expect($iguaisAoLegado)->toBeLessThan(5);
});

it('o handle é opaco e mais largo que o alias de exibição', function () {
    $handle = FanAlias::handle(7, 42);

    // 16 hex = 64 bits: adivinhar não é viável, e é isso que permite trocar o
    // member_id do POST por ele sem reabrir a enumeração.
    expect($handle)->toHaveLength(16)->toMatch('/^[0-9a-f]{16}$/');
    expect($handle)->not->toContain('42');
});

it('handles não colidem entre pares distintos', function () {
    // Ao contrário do alias de 4 dígitos, aqui colisão seria um bug de verdade:
    // o handle identifica o alvo do Interesse. 64 bits sobre 1.000 pares.
    $handles = collect(range(1, 500))
        ->flatMap(fn ($id) => [FanAlias::handle(1, $id), FanAlias::handle(2, $id)]);

    expect($handles->unique())->toHaveCount(1000);
});

it('resolve o handle de volta apenas dentro dos candidatos informados', function () {
    $candidatos = [10, 20, 30];

    expect(FanAlias::resolveHandle(7, $candidatos, FanAlias::handle(7, 20)))->toBe(20);

    // Membro que existe, mas não está na lista que a tela mostraria: o handle
    // dele é indistinguível de um inventado. É a garantia que faz o
    // SendInterestRequest não virar oráculo.
    expect(FanAlias::resolveHandle(7, $candidatos, FanAlias::handle(7, 99)))->toBeNull();

    // Handle da MESMA pessoa, mas emitido para outra performer.
    expect(FanAlias::resolveHandle(7, $candidatos, FanAlias::handle(8, 20)))->toBeNull();

    expect(FanAlias::resolveHandle(7, $candidatos, 'lixo'))->toBeNull();
    expect(FanAlias::resolveHandle(7, $candidatos, ''))->toBeNull();
});

it('muda todos os pseudônimos quando a APP_KEY é rotacionada', function () {
    $antes = FanAlias::handle(7, 42);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(FanAlias::handle(7, 42))->not->toBe($antes);
});
