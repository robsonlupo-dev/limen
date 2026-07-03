<?php

namespace Database\Seeders\Concerns;

/**
 * Guard fail-closed para seeders que criam contas de teste com senha conhecida.
 *
 * Denylist (`if production return`) é fail-open: um typo em APP_ENV
 * (`prod`, `Production`, vazio, `prd`…) escapa do guard e cria contas de senha
 * default em produção real. Aqui usamos allowlist.
 *
 * O ambiente é decidido pela UNIÃO de dois sinais, ambos precisam ser seguros:
 *  1. APP_ENV BRUTO do processo (getenv/$_ENV/$_SERVER) — imune a
 *     `php artisan config:cache`, que faz o LoadEnvironmentVariables retornar
 *     cedo e o app()->environment() servir o valor cacheado, ignorando um
 *     override inline (`APP_ENV=production php artisan db:seed`).
 *  2. app()->environment() — o valor booted/cacheado do framework.
 *
 * Exigir os dois na allowlist fecha os dois sentidos do ataque/acidente:
 *  - `APP_ENV=production` inline sobre cache `staging` → aborta (sinal bruto);
 *  - `APP_ENV=local` inline sobre cache `production` → aborta (sinal framework).
 */
trait RefusesUnsafeEnvironment
{
    /** Ambientes onde dados sintéticos são permitidos. */
    private const SAFE_ENVIRONMENTS = ['local', 'testing', 'development', 'staging'];

    /**
     * Retorna true se for seguro semear. Caso contrário, avisa e retorna false
     * para o `run()` abortar sem criar nada.
     */
    protected function safeToSeed(): bool
    {
        if ($this->isEnvironment(self::SAFE_ENVIRONMENTS)) {
            return true;
        }

        $this->command?->error(sprintf(
            '%s cria contas de teste com senha conhecida e só roda em %s (sinais de APP_ENV: %s). Abortado.',
            class_basename(static::class),
            implode('/', self::SAFE_ENVIRONMENTS),
            implode(', ', $this->environmentSignals()) ?: 'nenhum',
        ));

        return false;
    }

    /**
     * Fail-closed pela união: só é true se TODO sinal de ambiente disponível
     * estiver em $allowed. Sem nenhum sinal → false (fail-closed).
     */
    protected function isEnvironment(array $allowed): bool
    {
        $signals = $this->environmentSignals();

        if ($signals === []) {
            return false;
        }

        foreach ($signals as $env) {
            if (! in_array($env, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Sinais de ambiente: APP_ENV bruto do processo + o do framework. */
    protected function environmentSignals(): array
    {
        return array_values(array_filter([
            $this->rawEnv('APP_ENV'),
            (string) app()->environment(),
        ], fn ($value) => is_string($value) && $value !== ''));
    }

    /**
     * Lê uma variável direto do ambiente do processo, sem passar pelo
     * env()/config do framework. Retorna null se ausente ou vazia.
     */
    protected function rawEnv(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
