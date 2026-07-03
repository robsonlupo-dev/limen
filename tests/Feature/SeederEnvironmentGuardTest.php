<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/**
 * O DatabaseSeeder cria admin@limen.test com senha default conhecida.
 * O guard precisa ser fail-closed: só semeia em ambientes na allowlist;
 * qualquer outro (produção e, principalmente, typos de APP_ENV) aborta.
 */
function runDatabaseSeederIn(string $env): void
{
    app()['env'] = $env;

    test()->artisan('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ]);
}

it('seeds the default admin in a safe environment', function () {
    runDatabaseSeederIn('local');

    expect(User::where('email', 'admin@limen.test')->exists())->toBeTrue();
});

it('refuses to seed the default admin in production', function () {
    runDatabaseSeederIn('production');

    expect(User::where('email', 'admin@limen.test')->exists())->toBeFalse();
});

it('refuses a default-password admin outside disposable envs (staging needs SEED_ADMIN_PASSWORD)', function () {
    // staging está na allowlist, mas o fallback Password1 só vale em
    // local/testing. Sem SEED_ADMIN_PASSWORD, o seeder deve abortar em vez de
    // criar admin@limen.test com senha pública num ambiente alcançável.
    app()['env'] = 'staging';

    expect(fn () => app(DatabaseSeeder::class)->run())
        ->toThrow(RuntimeException::class);

    expect(User::where('email', 'admin@limen.test')->exists())->toBeFalse();
});

it('refuses to seed on a misspelled production env (fail-closed, not fail-open)', function () {
    // Denylist antigo (`if production return`) deixava "prod" escapar e
    // criava o admin de senha default em produção real. Allowlist bloqueia.
    foreach (['prod', 'Production', 'prd', ''] as $typo) {
        runDatabaseSeederIn($typo);

        expect(User::where('email', 'admin@limen.test')->exists())
            ->toBeFalse("env \"{$typo}\" deveria abortar o seeder");
    }
});
