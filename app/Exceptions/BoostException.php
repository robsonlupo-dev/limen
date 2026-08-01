<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falhas de domínio do Boost pago (Sprint 11). Carrega um `reason` estável
 * (consumível pelo frontend) além da mensagem legível — mesmo molde do
 * InterestException.
 *
 * Saldo insuficiente NÃO usa esta exceção: quem cuida do débito é o
 * TokenService, que lança InsufficientBalanceException. O controller traduz as
 * duas para 422, cada uma com seu `reason`.
 */
class BoostException extends DomainException
{
    public const ALREADY_BOOSTED = 'already_boosted';

    public const NO_SLOTS = 'no_slots';

    public const NOT_ELIGIBLE = 'not_eligible';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyBoosted(): self
    {
        return new self(
            self::ALREADY_BOOSTED,
            'Seu perfil já está em destaque. Aguarde o destaque atual terminar.',
        );
    }

    public static function noSlots(int $max): self
    {
        return new self(
            self::NO_SLOTS,
            "As {$max} vagas de destaque estão ocupadas agora. Tente novamente em instantes.",
        );
    }

    public static function notEligible(): self
    {
        return new self(
            self::NOT_ELIGIBLE,
            'Só um perfil verificado e ativo pode ser destacado.',
        );
    }
}
