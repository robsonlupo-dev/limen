<?php

return [
    'only' => [
        // Public / landing
        'landing',
        'entrada',
        'links',
        'waitlist.store',

        // Textos jurídicos (públicos)
        'legal.content-policy',
        'legal.performance-contract',

        // Public performer catalog (no auth)
        'performers.public',
        'performers.public.show',

        // Auth
        'register',
        'register.store',
        'login',
        'login.store',
        'logout',

        // Login passwordless por código OTP (Sprint 11)
        'otp.request.show',
        'otp.request',
        'otp.verify.show',
        'otp.verify',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',

        // Email verification
        'verification.notice',
        'verification.send',

        // Catalog & follows
        'catalog',
        'catalog.show',
        'catalog.follow',
        'catalog.unfollow',

        // User preferences
        'preferences.update',
        'consumer.settings',
        'consumer.settings.discrete-mode',
        'consumer.settings.privacy',

        // Denúncia (compliance)
        'report.store',

        // Exclusão de conta (LGPD art. 18, VI)
        'account.deletion.request',
        'account.deletion.cancel',
        'account.deletion.confirm',
        'account.deletion.confirm.store',

        // Performer area (all performer.* web routes)
        'performer.dashboard',
        'performer.onboarding',
        'performer.onboarding.profile',
        'performer.onboarding.avatar',
        'performer.onboarding.kyc',
        'performer.documents',
        'performer.documents.accept',
        'performer.payouts.index',
        'performer.payouts.history',
        'performer.payouts.store',
        'performer.followers',
        // Notas privadas da performer sobre membros (Sprint 11): lista, salvar,
        // apagar. O membro não tem rota irmã aqui — a nota é só do lado dela.
        'performer.notes.index',
        'performer.notes.save',
        'performer.notes.destroy',
        // "Disponível para conversa" (Sprint 11): toggle do dashboard
        'performer.availability.toggle',
        // Boost pago (Sprint 11): destaca o perfil no topo do catálogo
        'performer.boost',
        // Live pública GRÁTIS (Sprint 15): estúdio da performer (LiveRoom.vue)
        'performer.live.start',
        'performer.live.stop',
        'performer.interests.send',
        'performer.interests.send-visitor',
        'performer.interests.index',
        'performer.profile.edit',
        'performer.profile.save',
        'performer.profile.photo',
        // Localizações da performer (Sprint 13): a tela de edição dá PUT aqui
        // (porta dedicada, separada do save do perfil).
        'performer.locations.update',
        // Leitura da foto efêmera recebida de um membro
        'performer.photos.image',

        // Stories da performer (publicar, listar, apagar, thumbnail do painel)
        'performer.stories.index',
        'performer.stories.store',
        'performer.stories.destroy',
        'performer.stories.image',

        // Galeria de fotos do perfil (Sprint 10): gestão pela performer +
        // serving público (usado no carrossel do perfil e no grid de edição)
        'performer.gallery.store',
        'performer.gallery.destroy',
        'performer.gallery.reorder',
        'performer.gallery.image',
        // Permissões por foto (Sprint 13): visibilidade + fila de pedidos +
        // conceder/revogar por handle do FanAlias
        'performer.gallery.visibility',
        'performer.gallery.requests',
        'performer.gallery.grant',
        'performer.gallery.revoke',

        // 2FA TOTP da performer
        'performer.2fa.show',
        'performer.2fa.enable',
        'performer.2fa.confirm',
        'performer.2fa.disable',
        'performer.2fa.recovery-codes',
        'performer.2fa.challenge',
        'performer.2fa.verify',

        // Consumer KYC Nível 2 (envio de selfie)
        'consumer.kyc.index',
        'consumer.kyc.submit',
        'consumer.kyc.waiting',

        // Consumer panel
        'consumer.dashboard',

        // Perfil do membro (interesses + "o que estou buscando")
        'consumer.profile.edit',
        'consumer.profile.update',
        'consumer.profile.lifestyle-tier',

        // Favoritos do membro (bookmark privado — a performer não tem rota
        // irmã aqui, e não é para ganhar uma).
        'favorites.index',
        'favorites.toggle',

        // Solicitar acesso a foto privada da galeria (Sprint 13)
        'photos.access.request',

        // Buscas salvas do membro (combinações de filtros do catálogo). Área de
        // membro, sem lado da performer — mesma disciplina dos favoritos.
        'saved-searches.index',
        'saved-searches.store',
        'saved-searches.destroy',

        // Consumer tips
        'tips.send',

        // Live pública GRÁTIS (Sprint 15): viewer do membro (LiveViewer.vue) —
        // gorjeta/presente durante a live + renovação do token.
        'live.refresh',
        'gifts.send',
        'gifts.catalog',

        // Chamada privada 1:1 (Sprint 15): o membro pede/heartbeat/renova/encerra;
        // a performer aceita/recusa/configura. Consumidas por fetch (JSON).
        'call.request',
        'call.accept',
        'call.decline',
        'call.heartbeat',
        'call.token-refresh',
        'call.end',
        'performer.call.settings',

        // Group show 1:X (Sprint 15): performer inicia/encerra + resolve upgrade;
        // membro entra/heartbeat/renova/sai/pede upgrade. Consumidas por fetch.
        'group.start',
        'group.stop',
        'group.upgrade.accept',
        'group.upgrade.decline',
        'group.join',
        'group.heartbeat',
        'group.token-refresh',
        'group.leave',
        'group.upgrade.request',

        // Consumer interests (Interesse Controlado)
        'interests.index',
        'interests.unlock',
        'interests.opt-out',

        // Fotos efêmeras do membro (envio, compartilhamento, revogação, leitura)
        'member.photos.store',
        'member.photos.share',
        'member.photos.destroy',
        'member.photos.image',

        // Stories do lado do membro (feed + serving autenticado por sessão).
        // Não há versão assinada destas rotas de propósito — § 2.3.
        'stories.feed',
        'stories.image',

        // Consumer wallet
        'wallet.index',
        'wallet.history',
        'wallet.purchase',
        'wallet.pending',

        // Consumer subscriptions (Círculos)
        'subscribe.index',
        'subscribe.store',
        'subscribe.cancel',

        // Admin back-office
        'admin.performers.tier.store',

        // Moderação (Sprint 13): fila de denúncias em Inertia/Vue. Área separada
        // de /admin/* — moderator OU admin. As telas usam route() para navegar e
        // dar PATCH no status, então os nomes precisam do allowlist.
        'moderacao.reports.index',
        'moderacao.reports.show',
        'moderacao.reports.update',
        // Visualizador da prova retida: a tela de detalhe monta o src da <img> e
        // o fetch do corpo da mensagem por route().
        'moderacao.evidence.photo',
        'moderacao.evidence.story',
        'moderacao.evidence.message',

        // Chat (canal aberto pós-desbloqueio de Interesse)
        'chat.index',
        'chat.show',
        'chat.messages.store',
        'chat.access.open',
        'chat.performer.start',
    ],
];
