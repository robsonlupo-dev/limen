<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no envio de presente (M.13.6). `reason` estável para o front;
 * o controller traduz para HTTP. Como o Vue fala com rotas WEB, quem responde
 * precisa de `response()->json()` explícito (convenção das duas portas de auth).
 *
 * Status por motivo (o controller mapeia):
 *  - SELF        → 422 (a performer não se presenteia)
 *  - UNAVAILABLE → 422 (presente inativo / preço fora do múltiplo de 4)
 */
class GiftException extends DomainException
{
    public const SELF = 'self';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function self(): self
    {
        return new self(self::SELF, 'Você não pode enviar um presente para si mesma.');
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'Presente indisponível.');
    }
}
