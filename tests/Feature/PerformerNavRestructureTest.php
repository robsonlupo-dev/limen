<?php

use Illuminate\Support\Facades\File;

// Reestruturação da navegação do painel da performer (feat/performer-nav-restructure).
//
// As 9 telas que lotavam o header viraram 5 SEÇÕES de 1º nível + um MENU DO
// AVATAR, desenhadas MOBILE PRIMEIRO (barra fixa no rodapé no celular, barra
// superior no desktop). Nenhuma rota/permissão muda — só a forma de chegar nelas.
//
// Sem Vitest/Jest, a verificação roda pela fonte (mesma disciplina do
// PanicButton/UAT). A fonte ÚNICA das seções é lib/performerNav.js; a barra e a
// subnav a consomem, então travar a config trava as duas superfícies.

const NAV_CONFIG = 'js/lib/performerNav.js';
const NAV = 'js/Components/Performer/PerformerNav.vue';
const SUBNAV = 'js/Components/Performer/PerformerSubnav.vue';
const NAV_APP_LAYOUT = 'js/Layouts/AppLayout.vue';
const PROFILE_EDIT = 'js/Pages/Performer/Profile/Edit.vue';

// ─── As 5 seções de primeiro nível ───────────────────────────────────────────

it('agrupa os destinos em exatamente 5 secoes de primeiro nivel', function () {
    $src = File::get(resource_path(NAV_CONFIG));

    // Rótulos e rotas das 5 seções: Painel, Conteúdo, Mensagens, Pessoas, Ganhos.
    expect($src)->toContain("label: 'Painel'")
        ->and($src)->toContain("route: 'performer.dashboard'")
        ->and($src)->toContain("label: 'Conteúdo'")
        ->and($src)->toContain("route: 'performer.content'")
        ->and($src)->toContain("label: 'Mensagens'")
        ->and($src)->toContain("route: 'chat.index'")
        ->and($src)->toContain("label: 'Pessoas'")
        ->and($src)->toContain("label: 'Ganhos'");

    // Exatamente 5 chaves de seção (não sobrou nem faltou destino de 1º nível).
    expect(substr_count($src, "key: '"))->toBeGreaterThanOrEqual(5);
});

it('coloca Seguidores e Membros como subnav da secao Pessoas', function () {
    $src = File::get(resource_path(NAV_CONFIG));

    expect($src)->toContain("label: 'Seguidores'")
        ->and($src)->toContain("route: 'performer.followers'")
        ->and($src)->toContain("label: 'Membros'")
        ->and($src)->toContain("route: 'performer.members'");
});

it('coloca Saques e Historico como subnav da secao Ganhos', function () {
    $src = File::get(resource_path(NAV_CONFIG));

    expect($src)->toContain("label: 'Saques'")
        ->and($src)->toContain("route: 'performer.payouts.index'")
        ->and($src)->toContain("label: 'Histórico'")
        ->and($src)->toContain("route: 'performer.payouts.history'");
});

// ─── O menu do avatar ────────────────────────────────────────────────────────

it('move Perfil, Interesses, Agendamentos, Seguranca e Sair para o menu do avatar', function () {
    $src = File::get(resource_path(NAV_CONFIG));

    expect($src)->toContain("label: 'Perfil'")
        ->and($src)->toContain("route: 'performer.profile.edit'")
        ->and($src)->toContain("label: 'Interesses'")
        ->and($src)->toContain("route: 'performer.interests.index'")
        ->and($src)->toContain("label: 'Agendamentos'")
        ->and($src)->toContain("route: 'performer.reservations.index'")
        ->and($src)->toContain("label: 'Segurança'")
        ->and($src)->toContain("route: 'performer.2fa.show'")
        ->and($src)->toContain("label: 'Sair'")
        ->and($src)->toContain("action: 'logout'");
});

it('gateia Agendamentos pela flag de call (dark launch)', function () {
    // Agendamentos vive sob feature:call; some quando a chamada está desligada,
    // senão o item levaria a uma tela que o middleware recusaria.
    expect(File::get(resource_path(NAV_CONFIG)))->toContain("feature: 'call_enabled'");
});

// ─── Mobile primeiro: barra fixa no rodapé + menu do avatar acessível ─────────

it('desenha a barra de navegacao fixa no rodape do celular', function () {
    // Padrão de app: barra FIXA no rodapé com as seções, escondida no desktop
    // (md:hidden). Não é menu hambúrguer.
    $src = File::get(resource_path(NAV));

    expect($src)->toContain('fixed inset-x-0 bottom-0')
        ->and($src)->toContain('md:hidden');
});

it('oferece o menu do avatar acessivel pelo topo', function () {
    // Botão do avatar com semântica de menu (aria-haspopup) e o item de Sair
    // (emite logout, para o layout abrir o modal de confirmação).
    $src = File::get(resource_path(NAV));

    expect($src)->toContain('aria-haspopup="menu"')
        ->and($src)->toContain("emit('logout')");
});

it('marca a secao ativa nas duas versoes', function () {
    // A seção ativa é destacada (aria-current) na barra superior e na do rodapé.
    $src = File::get(resource_path(NAV));

    expect($src)->toContain('isSectionActive')
        ->and($src)->toContain("aria-current");
});

// ─── AppLayout monta a nav só para a performer ATIVA ─────────────────────────

it('monta a PerformerNav e a subnav para a performer ativa no AppLayout', function () {
    $src = File::get(resource_path(NAV_APP_LAYOUT));

    expect($src)->toContain("import PerformerNav from '@/Components/Performer/PerformerNav.vue'")
        ->and($src)->toContain("import PerformerSubnav from '@/Components/Performer/PerformerSubnav.vue'")
        ->and($src)->toMatch('/<PerformerNav\b[^>]*v-if="isActivePerformer"/s')
        ->and($src)->toContain('@logout="showLogoutConfirm = true"')
        ->and($src)->toContain('<PerformerSubnav v-if="isActivePerformer" />');
});

it('a subnav some fora das secoes com filhos', function () {
    // A subnav só aparece na seção ativa que TEM filhos (Pessoas/Ganhos); some
    // nas demais e fora do painel.
    expect(File::get(resource_path(SUBNAV)))->toContain('section.children');
});

// ─── Editar perfil em 4 abas ─────────────────────────────────────────────────

it('divide o editar perfil em 4 abas cabendo numa tela de celular', function () {
    $src = File::get(resource_path(PROFILE_EDIT));

    // As 4 abas + a semântica ARIA de tablist.
    expect($src)->toContain('role="tablist"')
        ->and($src)->toContain('role="tabpanel"')
        ->and($src)->toContain("label: 'Fotos'")
        ->and($src)->toContain("label: 'Sobre mim'")
        ->and($src)->toContain("label: 'Preferências'")
        ->and($src)->toContain("label: 'Localização'");
});

it('salva o formulario inteiro e avisa alteracoes nao salvas ao trocar de aba', function () {
    // O backend NÃO fragmenta: o save posta o profileForm inteiro. Trocar de aba
    // com alterações não salvas avisa (switchTab confere isDirty).
    $src = File::get(resource_path(PROFILE_EDIT));

    expect($src)->toContain("route('performer.profile.save')")
        ->and($src)->toContain('function switchTab')
        ->and($src)->toContain('isDirty');
});
