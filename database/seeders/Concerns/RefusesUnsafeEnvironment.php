<?php

namespace Database\Seeders\Concerns;

/**
 * Guard fail-closed para seeders que criam contas de teste com senha conhecida.
 *
 * Denylist (`if production return`) é fail-open: um typo em APP_ENV
 * (`prod`, `Production`, vazio, `prd`…) escapa do guard e cria um admin de
 * senha default em produção real. Aqui usamos allowlist: só rodamos em
 * ambientes explicitamente seguros; qualquer outro (incluindo produção e
 * qualquer valor desconhecido) aborta.
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
        if (app()->environment(self::SAFE_ENVIRONMENTS)) {
            return true;
        }

        $this->command?->error(sprintf(
            '%s cria contas de teste com senha conhecida e só roda em %s (ambiente atual: "%s"). Abortado.',
            class_basename(static::class),
            implode('/', self::SAFE_ENVIRONMENTS),
            app()->environment(),
        ));

        return false;
    }
}
