<?php

use Illuminate\Support\Facades\File;

// Visibilidade da Saída rápida (PanicButton) — UAT cenário 63.
//
// O botão é perk de privacidade DO MEMBRO (Sprint 6): tira a Limen da tela
// quando alguém entra na sala. O relato do cenário 63 foi "o membro não vê o
// botão". A investigação mostrou que ele SEMPRE esteve montado — o que faltava
// era CONTRASTE: `bg-background/70` (70% do quase-preto sobre página quase-preta)
// deixava o disco invisível. Estes testes travam as duas coisas de uma vez:
//   (a) a matriz de montagem (membro vê, visitante não, performer mantém), e
//   (b) o contraste do disco, para a invisibilidade não voltar em silêncio.
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
    // de um lado. Membro e performer recebem a mesma saída.
    expect(File::get(resource_path(APP_LAYOUT)))
        ->toContain("import PanicButton from '@/Components/PanicButton.vue'")
        ->toContain('<PanicButton />');
});

it('nao expoe o panic button ao visitante deslogado, so ao membro logado', function () {
    // GuestLayout serve landing/catálogo/perfil públicos. O botão só monta sob
    // login: o membro que chega por link direto tem a saída; o visitante não
    // (o logout seria no-op e o botão não faria sentido na vitrine pública).
    expect(File::get(resource_path(GUEST_LAYOUT)))
        ->toMatch('/<PanicButton\s+v-if="isLoggedIn"\s*\/>/');
});

// ─── Contraste: o disco tem de ser visível (a causa raiz do cenário 63) ──────

it('desenha o panic button como disco opaco visivel, nao um fantasma translucido', function () {
    $src = File::get(resource_path(PANIC));

    // Regressão que causou o cenário 63: fundo translúcido sobre página escura.
    expect($src)->not->toContain('bg-background/70');

    // O disco precisa de superfície opaca + aro/borda que o destaquem do fundo.
    expect($src)->toContain('bg-surface')
        ->and($src)->toMatch('/\bring-gold\//')
        ->and($src)->toContain('border-gold/');
});

it('mantem o glifo discreto e a posicao do round 3 (nao vira um botao gritante)', function () {
    // O CONTRASTE é do disco, não do X: o glifo segue discreto (#6f6a62, pedido
    // do PO) e a posição segue no topo direito. Guarda contra "consertar" a
    // visibilidade tornando o ícone berrante ou movendo o botão.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('text-[#6f6a62]')
        ->and($src)->toContain('fixed top-4 right-4')
        ->and($src)->toContain('z-[10001]');
});

// ─── Folga no canto: o botão flutuante não pode cobrir o "Sair" ──────────────

it('reserva o canto do header para a saida rapida nos dois layouts', function () {
    // O botão é `fixed top-4 right-4`, teleportado — flutua sobre o header. Sem
    // folga à direita, em tela estreita ele cobria (e, invisível, interceptava o
    // clique de) "Sair"/nome. pr-16 abre o espaço nos dois headers.
    foreach ([APP_LAYOUT, GUEST_LAYOUT] as $layout) {
        expect(File::get(resource_path($layout)))->toContain('pr-16');
    }
});
