// Estrutura de navegação do painel da performer (feat/performer-nav-restructure).
//
// Fonte ÚNICA das seções e do menu do avatar — consumida pela barra superior
// (PerformerNav), pela subnav de seção (PerformerSubnav) e pela barra inferior
// mobile. Uma cópia só: se a lista divergisse entre a barra e a subnav, a seção
// ativa e seus filhos discordariam.
//
// Nada aqui muda rota, permissão ou backend: são os MESMOS destinos de sempre,
// só reagrupados. As 9 telas viram 5 seções de 1º nível + um menu de avatar.

// As 5 seções de primeiro nível. `route` é o destino do clique; `match` são os
// padrões Ziggy (route().current(...)) que acendem a seção como ativa — inclui as
// telas-filhas para a seção continuar marcada dentro da subnav. `badge` mapeia
// para nav_counts (bolinha de não-vistos). `children` é a subnav da seção.
export const PERFORMER_SECTIONS = [
    {
        key: 'painel',
        label: 'Painel',
        route: 'performer.dashboard',
        match: ['performer.dashboard'],
    },
    {
        key: 'conteudo',
        label: 'Conteúdo',
        route: 'performer.content',
        match: ['performer.content'],
    },
    {
        key: 'mensagens',
        label: 'Mensagens',
        route: 'chat.index',
        match: ['chat.*'],
        badge: 'messages',
    },
    {
        key: 'pessoas',
        label: 'Pessoas',
        route: 'performer.followers',
        match: ['performer.followers', 'performer.members'],
        children: [
            { label: 'Seguidores', route: 'performer.followers' },
            { label: 'Membros', route: 'performer.members' },
        ],
    },
    {
        key: 'ganhos',
        label: 'Ganhos',
        route: 'performer.payouts.index',
        match: ['performer.payouts.*', 'performer.earnings.*'],
        children: [
            { label: 'Extrato', route: 'performer.earnings.index' },
            { label: 'Saques', route: 'performer.payouts.index' },
            { label: 'Histórico', route: 'performer.payouts.history' },
        ],
    },
]

// Menu do avatar (canto superior direito). Perfil, Interesses, Agendamentos,
// Segurança e Sair saíram da barra principal para cá — o que antes lotava a linha.
// `feature` gateia o item pela flag Inertia (features.*): Agendamentos vive sob
// feature:call (dark launch), então some quando a chamada está desligada — o link
// levaria a uma tela que o middleware recusaria. `action:'logout'` não navega:
// dispara o modal de confirmação de saída do layout.
export const PERFORMER_MENU = [
    { key: 'perfil', label: 'Perfil', route: 'performer.profile.edit' },
    { key: 'interesses', label: 'Interesses', route: 'performer.interests.index' },
    { key: 'agendamentos', label: 'Agendamentos', route: 'performer.reservations.index', feature: 'call_enabled' },
    { key: 'seguranca', label: 'Segurança', route: 'performer.2fa.show' },
    { key: 'sair', label: 'Sair', action: 'logout' },
]

// Seção ativa: casa a URL corrente contra os padrões da seção via Ziggy. Uma só
// implementação para a barra e a subnav concordarem. Defensivo contra ambientes
// sem route().current (SSR/teste): retorna false em vez de estourar.
export function isSectionActive(section) {
    if (typeof route !== 'function') return false
    try {
        return (section.match ?? []).some((pattern) => route().current(pattern))
    } catch {
        return false
    }
}

export function isRouteActive(name) {
    if (typeof route !== 'function') return false
    try {
        return route().current(name)
    } catch {
        return false
    }
}
