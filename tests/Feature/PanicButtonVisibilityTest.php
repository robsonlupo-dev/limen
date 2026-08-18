<?php

use Illuminate\Support\Facades\File;

// Visibilidade da Saída rápida (PanicButton).
//
// O botão é perk de privacidade (Sprint 6): tira a Limen da tela quando alguém
// entra na sala. A reestruturação da navegação (feat/performer-nav-restructure)
// o tirou do header e o pôs numa pílula flutuante; o ajuste de painel
// (fix/panel-polish-v1) refinou a APRESENTAÇÃO, por decisão do PO:
//
//   - DESKTOP-ONLY: o ícone flutuante só aparece a partir de `md:` (`hidden
//     md:block`). No CELULAR a saída nativa (bloquear o aparelho) é mais rápida
//     que qualquer botão nosso, então a pílula some e a saída da conta fica no
//     menu do avatar/"Sair". O DUPLO-ESCAPE continua sendo a via de teclado, e
//     é SEMPRE ATIVO (independe do ícone estar renderizado) — cobrado por
//     PanicButtonLayerTest, então funciona no celular também.
//   - ÍCONE, canto SUPERIOR-ESQUERDO, cor de ALERTA (danger), pequeno e
//     discreto. O rótulo "Panic Button" (em INGLÊS, decisão do PO) e o atalho
//     Esc só aparecem no HOVER/FOCO.
//
// Estes testes travam: (a) a matriz de montagem, (b) o desktop-only, (c) o
// ícone/rótulo em inglês revelado no hover, (d) a cor de alerta e (e) a camada
// de topo teleportada/fixa. Como o projeto não tem Vitest/Jest, a verificação
// roda pela fonte .vue — mesma disciplina do PanicButtonLayerTest.

const PANIC = 'js/Components/PanicButton.vue';
const APP_LAYOUT = 'js/Layouts/AppLayout.vue';
const GUEST_LAYOUT = 'js/Layouts/GuestLayout.vue';

// ─── Matriz de montagem: membro vê, visitante não, performer mantém ──────────

it('monta o panic button para todo usuario autenticado (membro E performer)', function () {
    // AppLayout envolve TODA página autenticada — do catálogo do membro ao painel
    // da performer. Montado sem v-if de role/tier: não há gate que esconda o botão
    // de um lado. Membro e performer recebem a mesma saída.
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

// ─── Desktop-only: some no celular, o teclado cobre toda largura ─────────────

it('e desktop-only: o icone flutuante nao aparece no celular', function () {
    // `hidden md:block`: escondido por padrão, visível só a partir do breakpoint
    // md. No celular a saída nativa é mais rápida (decisão do PO) — o duplo-Escape
    // (PanicButtonLayerTest) segue como via de teclado em toda largura.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('hidden md:block');
});

// ─── Desenho: ícone em inglês revelado no hover, cor de alerta ───────────────

it('desenha um icone com rotulo Panic Button em ingles revelado no hover', function () {
    $src = File::get(resource_path(PANIC));

    // O rótulo é em INGLÊS (decisão do PO: menos legível de relance para quem
    // passa perto). Só aparece no HOVER/FOCO — o ícone sozinho é discreto. O
    // nome acessível (aria-label) carrega o texto e o atalho para o leitor de
    // tela em toda hora, mesmo com o rótulo visual escondido.
    expect($src)->toContain('Panic Button')
        ->and($src)->toContain('aria-label="Panic Button')
        // O rótulo nasce invisível e só o hover/foco o revela (opacidade, nunca
        // largura — não empurra layout).
        ->and($src)->toContain('opacity-0')
        ->and($src)->toContain('group-hover:opacity-100');
});

it('usa cor de alerta distinta do dourado da marca', function () {
    // Cor de ALERTA (`danger`), não o dourado da marca — a saída de emergência
    // tem que ser inconfundível e não ser lida como "fechar" (UAT 63).
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('bg-danger');
});

it('mantem o icone teleportado e fixo na camada de topo', function () {
    // A via VISÍVEL e INTOCÁVEL: teleportada para a raiz (fora de qualquer
    // stacking context de layout) e fixa na camada de topo (z-[10001], UM acima
    // do teto do projeto). Guarda contra remover o Teleport ou baixar a camada.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('<Teleport to="body">')
        ->and($src)->toContain('z-[10001]')
        ->and($src)->toContain('fixed');
});

it('flutua no canto superior-esquerdo, nunca a direita', function () {
    // Superior-esquerdo: longe do menu do avatar/"Sair" (topo direito), do toast
    // (inferior direito) e do aviso de reserva (inferior centro). Em nenhuma
    // largura a Saída rápida encosta no "Sair" normal — requisito de segurança.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('left-4')
        ->and($src)->toContain('top-4')
        ->and($src)->not->toContain('right-4');
});

// ─── Alinhamento: o header não reserva mais o canto do disco antigo ──────────

it('nao reserva mais o canto superior direito do header (disco removido)', function () {
    // A folga assimétrica `pr-16` que o header reservava para o disco antigo já
    // tinha saído; o header segue em `px-6` simétrico.
    expect(File::get(resource_path(APP_LAYOUT)))->not->toContain('pr-16');
});
