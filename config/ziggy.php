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
        'performer.interests.send',
        'performer.interests.send-visitor',
        'performer.interests.index',
        'performer.profile.edit',
        'performer.profile.save',
        'performer.profile.photo',
        // Leitura da foto efêmera recebida de um membro
        'performer.photos.image',

        // Stories da performer (publicar, listar, apagar, thumbnail do painel)
        'performer.stories.index',
        'performer.stories.store',
        'performer.stories.destroy',
        'performer.stories.image',

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

        // Consumer tips
        'tips.send',

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

        // Chat (canal aberto pós-desbloqueio de Interesse)
        'chat.index',
        'chat.show',
        'chat.messages.store',
        'chat.access.open',
        'chat.performer.start',
    ],
];
