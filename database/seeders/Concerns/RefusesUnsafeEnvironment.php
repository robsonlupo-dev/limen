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
     * Senha das contas sintéticas criadas por um seeder.
     *
     * O fallback conhecido (`Password1`) só é aceitável em ambientes
     * descartáveis (local/testing, exigidos pela UNIÃO de sinais — ver
     * isEnvironment). Em qualquer outro ambiente da allowlist — staging,
     * development — exige SEED_ADMIN_PASSWORD explícita, senão aborta: nunca
     * criar contas com credencial pública num ambiente alcançável (staging é
     * exposto via túnel e pelo vhost thelimen.com.br).
     *
     * Vive na trait, e não em um seeder, porque a regra tem de valer para todos
     * eles: um seeder com senha própria hardcoded reabre o buraco pelo lado —
     * safeToSeed() libera staging, e a credencial publicada no repo vira login
     * válido num host alcançável.
     */
    protected function seedPassword(): string
    {
        // Leitura bruta (imune a config:cache) com fallback para env().
        $password = $this->rawEnv('SEED_ADMIN_PASSWORD') ?? env('SEED_ADMIN_PASSWORD');
        if (is_string($password) && $password !== '') {
            return $password;
        }

        if ($this->isEnvironment(['local', 'testing'])) {
            return 'Password1';
        }

        throw new \RuntimeException(sprintf(
            'SEED_ADMIN_PASSWORD é obrigatória fora de local/testing: %s recusa-se a '
            . 'criar contas com senha default (sinais de APP_ENV: %s).',
            class_basename(static::class),
            implode(', ', $this->environmentSignals()) ?: 'nenhum',
        ));
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
