<?php

use Illuminate\Support\Facades\File;

// Visibilidade da Saída rápida (PanicButton).
//
// O botão é perk de privacidade DO MEMBRO (Sprint 6): tira a Limen da tela
// quando alguém entra na sala. Desde ago/2026 (pedido do PO) ele tem DUAS
// superfícies clicáveis + o teclado: um LINK DE TEXTO rotulado no header (ao lado
// do nome — a via descoberta, que o disco sozinho, lido como "fechar", não era) E
// o DISCO flutuante teleportado na camada de topo (a via sempre visível/intocável,
// que o link inline não é). Estes testes travam:
//   (a) a matriz de montagem (membro vê, visitante não, performer mantém),
//   (b) o link rotulado e legível, e
//   (c) o disco de contraste na camada de topo (a causa raiz do UAT cenário 63).
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

// ─── Desenho: link de texto rotulado E disco flutuante convivem ──────────────

it('desenha o panic button como link de texto rotulado', function () {
    $src = File::get(resource_path(PANIC));

    // O RÓTULO em texto é a via descoberta: o membro lê o que é, não adivinha um
    // glifo. E precisa de nome acessível para quem navega por leitor de tela.
    expect($src)->toContain('Panic Button')
        ->and($src)->toContain('aria-label="Panic Button');
});

it('mantem tambem o disco flutuante opaco na camada de topo (fallback do UAT 63)', function () {
    // O disco é a via SEMPRE VISÍVEL que o link inline não é: teleportado para a
    // raiz, fixo no topo direito, na camada de topo. Contraste é requisito de
    // segurança (cenário 63): disco opaco `bg-surface` com aro dourado, glifo
    // discreto. Guarda contra remover o disco OU deixá-lo translúcido de novo.
    $src = File::get(resource_path(PANIC));

    expect($src)->toContain('<Teleport to="body">')
        ->and($src)->toContain('fixed top-4 right-4')
        ->and($src)->toContain('z-[10001]')
        ->and($src)->toContain('bg-surface')
        ->and($src)->not->toContain('bg-background/70')
        ->and($src)->toContain('text-[#6f6a62]');
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

// ─── O disco flutua no canto: o header reserva a folga nos dois layouts ───────

it('reserva o canto do header para o disco flutuante nos dois layouts', function () {
    // O disco é `fixed top-4 right-4`, teleportado — flutua sobre o header. Sem
    // folga à direita, em tela estreita ele cobre (e, invisível, interceptava o
    // clique de) nome/Sair e o próprio link. pr-16 abre o espaço nos dois headers.
    foreach ([APP_LAYOUT, GUEST_LAYOUT] as $layout) {
        expect(File::get(resource_path($layout)))->toContain('pr-16');
    }
});
