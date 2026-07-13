<?php

return [
    'only' => [
        // Public / landing
        'landing',
        'entrada',
        'links',
        'waitlist.store',

        // Public catalog (reserved — route not yet defined; harmless until it is)
        'performers.public',

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

        // Consumer tips
        'tips.send',

        // Consumer wallet
        'wallet.index',
        'wallet.history',
        'wallet.purchase',
        'wallet.pending',
    ],
];
