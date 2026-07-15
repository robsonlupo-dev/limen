<?php

return [
    'only' => [
        // Public / landing
        'landing',
        'entrada',
        'links',
        'waitlist.store',

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

        // Performer area (all performer.* web routes)
        'performer.dashboard',
        'performer.onboarding',
        'performer.onboarding.profile',
        'performer.onboarding.avatar',
        'performer.payouts.index',
        'performer.payouts.history',
        'performer.payouts.store',
        'performer.followers',
        'performer.interests.send',
        'performer.interests.index',
        'performer.profile.edit',
        'performer.profile.save',
        'performer.profile.photo',

        // Consumer panel
        'consumer.dashboard',

        // Consumer tips
        'tips.send',

        // Consumer interests (Interesse Controlado)
        'interests.index',
        'interests.unlock',
        'interests.opt-out',

        // Consumer wallet
        'wallet.index',
        'wallet.history',
        'wallet.purchase',
        'wallet.pending',
    ],
];
