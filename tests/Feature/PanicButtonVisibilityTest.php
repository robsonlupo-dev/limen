<?php

use Illuminate\Support\Facades\File;

// Visibilidade da Saída rápida (PanicButton).
//
// O botão é perk de privacidade DO MEMBRO (Sprint 6): tira a Limen da tela
// quando alguém entra na sala. A reestruturação da navegação do painel
// (feat/performer-nav-restructure) tirou a saída do fluxo do header: o antigo
// link inline + o disco viraram UMA pílula flutuante ROTULADA "Saída rápida"
// (português), teleportada na camada de topo, em cor de ALERTA (danger,
// distinta do dourado da marca), no canto INFERIOR-ESQUERDO — longe do "Sair"
// do menu do avatar (topo direito), do toast (inferior direito) e do aviso de
// reserva (inferior centro). O duplo-Escape continua sendo a via de teclado
// (cobrado por PanicButtonLayerTest). Estes testes travam:
//   (a) a matriz de montagem (membro E performer veem; visitante não),
//   (b) o rótulo em português e o alerta, e
//   (c) a pílula teleportada/fixa na camada de topo (sempre visível/intocável).
//
// Como o projeto não tem Vitest/Jest, a verificação roda pela fonte .vue —
// mesma disciplina do PanicButtonLayerTest e do UatPhase1Round2Test.

const PANIC = 'js/Components/PanicButton.vue';
const APP_LAYOUT = 'js/Layouts/AppLayout.vue';
const GUEST_LAYOUT = 'js/Layouts/GuestLayout.vue';

// ─── Matriz de montagem: membro vê, visitante não, performer mantém ──────────

it('monta o panic button para todo usuario autenticado (membro E performer)', function () {
    // AppLayout envolve TODA página autenticada — do catálogo do membro ao painel
    // da performer. Montado sem v-if de role/tier: não há gate que esconda o botão
    // de um lado. Membro e performer recebem a mesma saída. A prop :lift-mobile só
    // ajusta a posição no celular (acima da barra de navegação do painel).
    expect(File::get(resource_path(APP_LAYOUT)))
        ->toContain("import PanicButton from '@/Components/PanicButton.vue'")
        ->toContain('<PanicButton');
});

it('nao expoe o panic button ao visitante deslogado, so ao membro logado', function () {
    // GuestLayout serve landing/catálogo/perfil públicos. O botão só monta sob
    // login: o membro que chega por link direto tem a saída; o visitante não
    // (o logout seria no-op e o botão não faria sentido na vitrine pública).
    expect(File::get(resource_path(GUEST_LAYOUT)))
        ->toMatch('/<PanicButton\s+v-if="isLoggedIn"\s*\/>/');
});

// ─── Desenho: pílula rotulada, em português, cor de alerta ───────────────────

it('desenha a saida como pilula rotulada em portugues', function () {
    $src = File::get(resource_path(PANIC));

    // O RÓTULO em texto é a via descoberta: a performer lê o que é, não adivinha um
    // glifo. Em PORTUGUÊS (a interface é pt-BR) e com nome acessível para leitor
    // de tela. Nunca mais "Panic Button" em inglês.
    expect($src)->toContain('Saída rápida')
        ->and($src)->toContain('aria-label="Saída rápida')
        ->and($src)->not->toContain('Panic Button');
});

it('usa cor de alerta distinta do dourado da marca', function () {
    // Cor de ALERTA (`danger`), não o dourado da marca nem o cinza discreto do
    // disco antigo — a saída de emergência tem que ser inconfundível e não ser
    // lida como "fechar" (regressão do UAT cenário 63).
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('bg-danger');
});

it('mantem a pilula teleportada e fixa na camada de topo', function () {
    // A pílula é a via SEMPRE VISÍVEL e INTOCÁVEL: teleportada para a raiz (fora de
    // qualquer stacking context de layout) e fixa na camada de topo (z-[10001], UM
    // acima do teto do projeto). Guarda contra remover o Teleport ou baixar a camada.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('<Teleport to="body">')
        ->and($src)->toContain('z-[10001]')
        ->and($src)->toContain('fixed');
});

it('flutua no canto inferior-esquerdo, nunca no topo direito', function () {
    // Inferior-esquerdo: longe do menu do avatar/"Sair" (topo direito), do toast
    // (inferior direito) e do aviso de reserva (inferior centro). Em nenhuma
    // largura a Saída rápida encosta no "Sair" normal — requisito de segurança.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('left-4')
        ->and($src)->not->toContain('top-4 right-4');
});

// ─── Alinhamento: o header não reserva mais o canto do disco antigo ──────────

it('nao reserva mais o canto superior direito do header (disco removido)', function () {
    // A pílula desceu para o canto inferior-esquerdo, então a folga assimétrica
    // `pr-16` que o header reservava para o disco no topo direito saiu — o header
    // volta a `px-6` simétrico (corrige a coluna de conteúdo "deslocada à esquerda"
    // percebida na tela larga).
    expect(File::get(resource_path(APP_LAYOUT)))->not->toContain('pr-16');
});
