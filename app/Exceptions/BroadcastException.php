<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no canal de transmissão (App\Services\BroadcastService).
 * Mesmo padrão dos outros: `reason` estável para o front, o chamador traduz p/ HTTP.
 */
class BroadcastException extends DomainException
{
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Teto diário de broadcasts atingido (protege servidor + seguidor). 422. */
    public static function dailyLimitReached(int $max): self
    {
        return new self(
            self::RATE_LIMITED,
            "Você já enviou {$max} transmissões nas últimas 24 horas. Tente de novo mais tarde.",
        );
    }
}
