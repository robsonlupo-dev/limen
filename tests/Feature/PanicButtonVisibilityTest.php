<?php

use Illuminate\Support\Facades\File;

// Visibilidade da Saída rápida (PanicButton).
//
// O botão é perk de privacidade DO MEMBRO (Sprint 6): tira a Limen da tela
// quando alguém entra na sala. Desde ago/2026 (pedido do PO) ele deixou de ser o
// disco flutuante teleportado e virou um LINK DE TEXTO no header, ao lado do nome
// do usuário — o disco lia como "fechar" e no UAT confundia o membro. Estes
// testes travam duas coisas:
//   (a) a matriz de montagem (membro vê, visitante não, performer mantém), e
//   (b) o desenho como link rotulado e legível — para não regredir ao disco nem
//       virar um ícone mudo que o membro precise adivinhar.
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

// ─── Desenho: link de texto rotulado, não mais o disco flutuante ─────────────

it('desenha o panic button como link de texto rotulado, nao um disco flutuante', function () {
    $src = File::get(resource_path(PANIC));

    // O RÓTULO em texto é o coração da mudança: o membro lê o que é, não adivinha
    // um glifo. E precisa de nome acessível para quem navega por leitor de tela.
    expect($src)->toContain('Panic Button')
        ->and($src)->toContain('aria-label="Panic Button');

    // Regressão que a mudança encerra: o disco flutuante teleportado no topo.
    expect($src)->not->toContain('fixed top-4 right-4')
        ->and($src)->not->toContain('<Teleport')
        ->and($src)->not->toContain('z-[10001]');
});

it('mantem o link discreto mas legivel (muted com pilula, hover de perigo)', function () {
    // "Discreto mas legível" (pedido do PO): tom `muted` que se lê no header, com
    // uma pílula de borda fina que o marca como controle intencional, e o hover
    // puxando para `danger` — saída de emergência sem gritar. Guarda contra dois
    // exageros opostos: virar berrante ou sumir de novo por falta de contraste.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('text-muted')
        ->and($src)->toMatch('/\bborder-frame\//')
        ->and($src)->toContain('hover:text-danger');
});

// ─── O link mora no header, ao lado do nome — não flutua mais ────────────────

it('nao reserva mais o canto do header (o botao deixou de flutuar)', function () {
    // O disco era `fixed top-4 right-4` e exigia `pr-16` para não cobrir "Sair".
    // Como link inline no fluxo do header, a folga não é mais necessária — e
    // mantê-la deixaria um buraco à direita. Trava a limpeza nos dois layouts.
    foreach ([APP_LAYOUT, GUEST_LAYOUT] as $layout) {
        expect(File::get(resource_path($layout)))->not->toContain('pr-16');
    }
});
