<?php

use Illuminate\Support\Facades\File;

// Reestruturação da navegação do painel do MEMBRO (feat/member-nav-restructure).
//
// As ~11 telas que lotavam o header (Meu Painel, Feed, Meu Perfil, Interesses,
// Interessadas, Quem me visitou, Salvos, Mensagens, Carteira, Chamadas, Círculos)
// viraram 5 SEÇÕES de 1º nível + um MENU DO AVATAR, desenhadas MOBILE PRIMEIRO
// (barra fixa no rodapé no celular, barra superior no desktop) — o mesmo padrão já
// aplicado no lado da performer. Nenhuma rota/permissão muda, só o caminho até elas.
//
// Sem Vitest/Jest, a verificação roda pela fonte (mesma disciplina do
// PerformerNavRestructure/PanicButton). A fonte ÚNICA das seções é lib/memberNav.js;
// a barra e a subnav a consomem, então travar a config trava as duas superfícies.

const M_NAV_CONFIG = 'js/lib/memberNav.js';
const M_NAV = 'js/Components/Member/MemberNav.vue';
const M_SUBNAV = 'js/Components/Member/MemberSubnav.vue';
const M_APP_LAYOUT = 'js/Layouts/AppLayout.vue';

// ─── As 5 seções de primeiro nível ───────────────────────────────────────────

it('agrupa os destinos do membro em exatamente 5 secoes de primeiro nivel', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Início'")
        ->and($src)->toContain("route: 'consumer.dashboard'")
        ->and($src)->toContain("label: 'Descobrir'")
        ->and($src)->toContain("label: 'Conexões'")
        ->and($src)->toContain("label: 'Mensagens'")
        ->and($src)->toContain("route: 'chat.index'")
        ->and($src)->toContain("label: 'Carteira'");

    // Exatamente 5 chaves de seção de 1º nível.
    expect(substr_count($src, "key: 'inicio'"))->toBe(1);
    expect(substr_count($src, "key: 'descobrir'"))->toBe(1);
    expect(substr_count($src, "key: 'conexoes'"))->toBe(1);
    expect(substr_count($src, "key: 'mensagens'"))->toBe(1);
    expect(substr_count($src, "key: 'carteira'"))->toBe(1);
});

it('coloca Catalogo e Feed como subnav da secao Descobrir', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Catálogo'")
        ->and($src)->toContain("route: 'catalog'")
        ->and($src)->toContain("label: 'Feed'")
        ->and($src)->toContain("route: 'feed'");
});

it('coloca Interesses, Interessadas, Quem me visitou e Salvos como subnav de Conexoes', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Interesses'")
        ->and($src)->toContain("route: 'interests.index'")
        ->and($src)->toContain("label: 'Interessadas'")
        ->and($src)->toContain("route: 'consumer.hearts.index'")
        ->and($src)->toContain("label: 'Quem me visitou'")
        ->and($src)->toContain("route: 'consumer.visitors.index'")
        ->and($src)->toContain("label: 'Salvos'")
        ->and($src)->toContain("route: 'favorites.index'");
});

it('coloca Mensagens e Chamadas como subnav da secao Mensagens', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Chamadas'")
        ->and($src)->toContain("route: 'reservations.index'");
});

it('coloca Tokens e Circulos como subnav da secao Carteira', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Tokens'")
        ->and($src)->toContain("route: 'wallet.index'")
        ->and($src)->toContain("label: 'Círculos'")
        ->and($src)->toContain("route: 'subscribe.index'");
});

it('gateia Chamadas pela flag de call (dark launch)', function () {
    // Chamadas vive sob feature:call; some quando a chamada está desligada, senão
    // o item levaria a uma tela que o middleware recusaria.
    expect(File::get(resource_path(M_NAV_CONFIG)))->toContain("feature: 'call_enabled'");
});

// ─── O menu do avatar ────────────────────────────────────────────────────────

it('move Meu Perfil, Configuracoes e Sair para o menu do avatar', function () {
    $src = File::get(resource_path(M_NAV_CONFIG));

    expect($src)->toContain("label: 'Meu Perfil'")
        ->and($src)->toContain("route: 'consumer.profile.edit'")
        ->and($src)->toContain("label: 'Configurações'")
        ->and($src)->toContain("route: 'consumer.settings'")
        ->and($src)->toContain("label: 'Sair'")
        ->and($src)->toContain("action: 'logout'");
});

// ─── Mobile primeiro: barra fixa no rodapé + menu do avatar acessível ─────────

it('desenha a barra de navegacao fixa no rodape do celular', function () {
    // Padrão de app: barra FIXA no rodapé com as seções, escondida no desktop
    // (md:hidden). Não é menu hambúrguer.
    $src = File::get(resource_path(M_NAV));

    expect($src)->toContain('fixed inset-x-0 bottom-0')
        ->and($src)->toContain('md:hidden');
});

it('oferece o menu do avatar acessivel pelo topo', function () {
    $src = File::get(resource_path(M_NAV));

    expect($src)->toContain('aria-haspopup="menu"')
        ->and($src)->toContain("emit('logout')");
});

it('marca a secao ativa nas duas versoes', function () {
    $src = File::get(resource_path(M_NAV));

    expect($src)->toContain('isSectionActive')
        ->and($src)->toContain('aria-current');
});

// ─── AppLayout monta a nav só para o membro ──────────────────────────────────

it('monta a MemberNav e a subnav para o membro no AppLayout', function () {
    $src = File::get(resource_path(M_APP_LAYOUT));

    expect($src)->toContain("import MemberNav from '@/Components/Member/MemberNav.vue'")
        ->and($src)->toContain("import MemberSubnav from '@/Components/Member/MemberSubnav.vue'")
        ->and($src)->toMatch('/<MemberNav\b[^>]*v-else-if="isConsumer"/s')
        ->and($src)->toContain('@logout="showLogoutConfirm = true"')
        ->and($src)->toMatch('/<MemberSubnav\b[^>]*v-else-if="isConsumer"/s');
});

it('a subnav some fora das secoes com filhos', function () {
    // A subnav só aparece na seção ativa que TEM filhos; some na seção Início
    // (sem filhos) e fora do painel.
    expect(File::get(resource_path(M_SUBNAV)))->toContain('section.children');
});
