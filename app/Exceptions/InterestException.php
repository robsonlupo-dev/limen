<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falhas de domínio do Sistema de Interesse Controlado. Carrega um `reason`
 * estável (consumível pelo frontend) além da mensagem legível.
 *
 * O opt-out do membro NÃO usa esta exceção: por design ele é silencioso (a
 * performer não pode saber que o membro optou por sair). Ver InterestService.
 */
class InterestException extends DomainException
{
    public const COOLDOWN = 'cooldown';

    public const DAILY_LIMIT = 'daily_limit';

    public const INVALID_TARGET = 'invalid_target';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function cooldown(int $days): self
    {
        return new self(
            self::COOLDOWN,
            "Você já demonstrou interesse nesta pessoa nos últimos {$days} dias.",
        );
    }

    public static function dailyLimit(int $limit): self
    {
        return new self(
            self::DAILY_LIMIT,
            "Você atingiu o limite de {$limit} interesses por dia.",
        );
    }

    public static function invalidTarget(): self
    {
        return new self(
            self::INVALID_TARGET,
            'Só é possível demonstrar interesse em um membro.',
        );
    }
}
