<?php

use Illuminate\Support\Facades\File;

// Guarda da SAÍDA sempre disponível do PanicButton.
//
// Histórico: até ago/2026 este arquivo cobrava um invariante de CAMADA — o disco
// flutuante em z-[10001], teleportado para a raiz, que nada podia cobrir. A pedido
// do PO o botão virou um link de texto no header (ver PanicButton.vue e
// PanicButtonVisibilityTest). Um link inline no fluxo do header PODE ser coberto
// por um modal — então a garantia "nenhuma tela tira minha saída" mudou de dono:
// deixou de ser o z-index e passou a ser o DUPLO-ESCAPE, um listener em `window`
// que dispara mesmo sob overlay, sem depender de o link estar visível ou clicável.
//
// É isso que este arquivo trava agora. O link é a via descoberta/rotulada; o
// teclado é a via que nenhuma tela cobre. Ao mexer no componente, preserve as duas.
//
// O projeto não tem Vitest/Jest, então o teste roda pela fonte .vue.

const PANIC_COMPONENT = 'resources/js/Components/PanicButton.vue';

it('mantem a saida por teclado sempre ativa (listener global de keydown)', function () {
    // O listener vive em `window`, não num elemento do fluxo: é o que faz a saída
    // disparar mesmo com um modal cobrindo o header. Registrado no mount e limpo
    // no unmount para não vazar entre páginas.
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain("window.addEventListener('keydown', onKeydown)")
        ->and($src)->toContain("window.removeEventListener('keydown', onKeydown)");
});

it('exige duplo-escape para sair, nunca um escape solto', function () {
    // Um Escape sozinho fecha modal e não pode virar evasão acidental. A saída só
    // dispara com dois Escapes dentro de DOUBLE_ESCAPE_MS — a intenção deliberada
    // que substitui o clique quando o link está coberto.
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain('DOUBLE_ESCAPE_MS')
        ->and($src)->toMatch("/event\.key\s*!==\s*'Escape'/");
});

it('sai sem esperar o logout, com replace que nao volta pelo Voltar', function () {
    // A saída não pode prender o membro na tela da Limen: o logout é fire-and-forget
    // com keepalive (sobrevive à navegação) e a troca de página é `location.replace`
    // (não empilha entrada, o Voltar não devolve a Limen). Contrato do componente.
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain('keepalive: true')
        ->and($src)->toContain('window.location.replace(target.value)');
});
