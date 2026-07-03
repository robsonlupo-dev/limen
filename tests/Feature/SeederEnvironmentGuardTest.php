<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * O DatabaseSeeder cria admin@limen.test com senha default conhecida.
 * O guard precisa ser fail-closed E imune a config:cache: lê o APP_ENV bruto
 * do processo (getenv/$_ENV/$_SERVER), não app()->environment(), que com
 * `php artisan config:cache` serve o valor cacheado e ignora overrides inline.
 */

/**
 * Aplica overrides de env no processo (as três fontes que o guard lê) e
 * restaura o estado anterior ao final — indispensável para não vazar APP_ENV
 * para os demais testes da suíte. `null` remove a variável.
 */
function withProcessEnv(array $overrides, Closure $fn): void
{
    $saved = [];
    foreach ($overrides as $key => $value) {
        $saved[$key] = [
            getenv($key),
            array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
            array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null,
        ];

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    try {
        $fn();
    } finally {
        foreach ($saved as $key => [$getenv, $hadEnv, $envVal, $hadServer, $serverVal]) {
            $getenv === false ? putenv($key) : putenv("{$key}={$getenv}");
            $hadEnv ? $_ENV[$key] = $envVal : $_ENV = array_diff_key($_ENV, [$key => null]);
            $hadServer ? $_SERVER[$key] = $serverVal : $_SERVER = array_diff_key($_SERVER, [$key => null]);
        }
    }
}

/** Invoca o seeder como o comando db:seed faz em produção (dentro de unguarded). */
function runSeeder(): void
{
    Model::unguarded(fn () => app(DatabaseSeeder::class)->__invoke());
}

function adminExists(): bool
{
    return User::where('email', 'admin@limen.test')->exists();
}

it('seeds the default admin in a safe environment', function () {
    withProcessEnv(['APP_ENV' => 'local', 'SEED_ADMIN_PASSWORD' => null], function () {
        runSeeder();
    });

    expect(adminExists())->toBeTrue();
});

it('refuses to seed the default admin in production', function () {
    withProcessEnv(['APP_ENV' => 'production'], function () {
        runSeeder();
    });

    expect(adminExists())->toBeFalse();
});

it('refuses on misspelled production envs (fail-closed, not fail-open)', function () {
    // Denylist antigo (`if production return`) deixava "prod" escapar e criava
    // o admin de senha default em produção real. Allowlist bloqueia.
    foreach (['prod', 'Production', 'prd'] as $typo) {
        withProcessEnv(['APP_ENV' => $typo], function () {
            runSeeder();
        });

        expect(adminExists())->toBeFalse("APP_ENV \"{$typo}\" deveria abortar o seeder");
    }
});

it('honors the inline APP_ENV override even when the framework env is cached as staging', function () {
    // Reproduz o bug do servidor: com config:cache o Laravel serve
    // app()->environment() do cache (staging) e ignora o `APP_ENV=production`
    // inline. O guard lê o valor bruto do processo, então aborta mesmo assim.
    app()['env'] = 'staging';

    withProcessEnv(['APP_ENV' => 'production'], function () {
        runSeeder();
    });

    expect(adminExists())->toBeFalse();
});

it('refuses inline APP_ENV=local when the framework env is cached as production', function () {
    // Sentido inverso do bypass: num prod com config:cache, `APP_ENV=local`
    // inline não pode rebaixar o guard. A união dos sinais (raw=local +
    // framework=production) tem um sinal inseguro → aborta.
    app()['env'] = 'production';

    withProcessEnv(['APP_ENV' => 'local'], function () {
        runSeeder();
    });

    expect(adminExists())->toBeFalse();
});

it('refuses a default-password admin on staging without SEED_ADMIN_PASSWORD', function () {
    withProcessEnv(['APP_ENV' => 'staging', 'SEED_ADMIN_PASSWORD' => null], function () {
        expect(fn () => runSeeder())
            ->toThrow(RuntimeException::class);
    });

    expect(adminExists())->toBeFalse();
});

it('still requires SEED_ADMIN_PASSWORD when inline local is layered over cached staging', function () {
    // A união deixa passar o safeToSeed (local+staging ambos seguros), mas
    // seedPassword exige local/testing em TODOS os sinais — staging quebra
    // isso, então não há como forçar Password1 via override inline: aborta.
    app()['env'] = 'staging';

    withProcessEnv(['APP_ENV' => 'local', 'SEED_ADMIN_PASSWORD' => null], function () {
        expect(fn () => runSeeder())->toThrow(RuntimeException::class);
    });

    expect(adminExists())->toBeFalse();
});

it('uses the provided SEED_ADMIN_PASSWORD for every base account on staging', function () {
    withProcessEnv(['APP_ENV' => 'staging', 'SEED_ADMIN_PASSWORD' => 'Str0ng-Staging-Pw!'], function () {
        runSeeder();
    });

    // As três contas base nunca podem nascer com a senha pública Password1.
    foreach (['admin@limen.test', 'performer@limen.test', 'consumer@limen.test'] as $email) {
        $user = User::where('email', $email)->first();

        expect($user)->not->toBeNull("{$email} deveria existir")
            ->and(Hash::check('Str0ng-Staging-Pw!', $user->password))->toBeTrue()
            ->and(Hash::check('Password1', $user->password))->toBeFalse("{$email} não pode usar Password1");
    }
});
