// Estrutura de navegação do painel do MEMBRO (feat/member-nav-restructure).
//
// Fonte ÚNICA das seções e do menu do avatar — consumida pela barra superior
// (MemberNav), pela subnav de seção (MemberSubnav) e pela barra inferior mobile.
// Uma cópia só: se a lista divergisse entre a barra e a subnav, a seção ativa e
// seus filhos discordariam. Espelha lib/performerNav.js (o padrão do lado da
// performer), reaproveitando os mesmos helpers de "seção ativa".
//
// Nada aqui muda rota, permissão ou backend: são os MESMOS destinos de sempre,
// só reagrupados. As ~11 telas que lotavam o header viram 5 seções de 1º nível
// + um menu de avatar.

// Os helpers de casamento de rota são GENÉRICOS (não têm nada de performer) —
// reusamos a implementação única em vez de duplicá-la aqui.
export { isSectionActive, isRouteActive } from './performerNav'

// As 5 seções de primeiro nível. `route` é o destino do clique; `match` são os
// padrões Ziggy (route().current(...)) que acendem a seção como ativa — inclui as
// telas-filhas para a seção continuar marcada dentro da subnav. `badge` mapeia
// para nav_counts (bolinha de não-vistos). `children` é a subnav da seção; um
// filho pode carregar seu próprio `badge` e ser gateado por `feature`.
export const MEMBER_SECTIONS = [
    {
        key: 'inicio',
        label: 'Início',
        route: 'consumer.dashboard',
        match: ['consumer.dashboard'],
    },
    {
        key: 'descobrir',
        label: 'Descobrir',
        route: 'catalog',
        match: ['catalog', 'catalog.show', 'feed', 'saved.index'],
        children: [
            { label: 'Catálogo', route: 'catalog' },
            { label: 'Feed', route: 'feed' },
            // Salvos (Onda 3 §3.1): peças de conteúdo guardadas pelo membro. Fica em
            // Descobrir, ao lado do Feed, porque é conteúdo — o "Favoritos" abaixo é
            // de PERFIS (rota favorites.index), coisa diferente.
            { label: 'Salvos', route: 'saved.index' },
        ],
    },
    {
        key: 'conexoes',
        label: 'Conexões',
        route: 'interests.index',
        match: ['interests.index', 'consumer.hearts.index', 'consumer.visitors.index', 'favorites.index', 'consumer.channels.index'],
        badge: 'hearts',
        children: [
            { label: 'Interesses', route: 'interests.index' },
            { label: 'Interessadas', route: 'consumer.hearts.index', badge: 'hearts' },
            // Canais (Onda 2): broadcasts das performers que o membro segue.
            { label: 'Canais', route: 'consumer.channels.index', badge: 'channels' },
            { label: 'Quem me visitou', route: 'consumer.visitors.index' },
            // Perfis favoritados (rota favorites.index) — renomeado de "Salvos" para
            // "Favoritos" para não colidir com "Salvos" de CONTEÚDO (Descobrir, §3.1).
            { label: 'Favoritos', route: 'favorites.index' },
        ],
    },
    {
        key: 'mensagens',
        label: 'Mensagens',
        route: 'chat.index',
        match: ['chat.index', 'chat.show', 'reservations.index', 'custom-orders.index'],
        badge: 'messages',
        children: [
            { label: 'Mensagens', route: 'chat.index', badge: 'messages' },
            // Chamadas agendadas vivem sob feature:call (dark launch): o filho
            // some quando a chamada está desligada — o link levaria a uma tela
            // que o middleware recusaria.
            { label: 'Chamadas', route: 'reservations.index', feature: 'call_enabled' },
            // Encomendas sob medida (Onda 4 §4.3): pedidos de conteúdo personalizado.
            // Badge = entregas aguardando o membro aprovar/relatar (delivered).
            { label: 'Encomendas', route: 'custom-orders.index', badge: 'custom_orders' },
        ],
    },
    {
        key: 'carteira',
        label: 'Carteira',
        route: 'wallet.index',
        match: ['wallet.index', 'wallet.history', 'subscribe.index', 'fanclub.mine'],
        children: [
            { label: 'Tokens', route: 'wallet.index' },
            { label: 'Círculos', route: 'subscribe.index' },
            // Fã-clubes assinados (Onda 4): assinaturas por-performer, à parte dos Círculos.
            { label: 'Assinaturas', route: 'fanclub.mine' },
        ],
    },
]

// Menu do avatar (canto superior direito). Meu Perfil, Configurações e Sair
// saíram da barra principal para cá — o que antes lotava a linha. O membro não
// tem 2FA (é perk da performer), então o slot de "conta" aponta para as
// Configurações (privacidade + preferências + exclusão de conta).
// `action:'logout'` não navega: dispara o modal de confirmação de saída do layout.
export const MEMBER_MENU = [
    { key: 'perfil', label: 'Meu Perfil', route: 'consumer.profile.edit' },
    { key: 'config', label: 'Configurações', route: 'consumer.settings' },
    { key: 'sair', label: 'Sair', action: 'logout' },
]
