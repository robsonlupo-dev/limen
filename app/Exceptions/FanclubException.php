<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no Fã-Clube (App\Services\FanclubService, Onda 4 — fork da assinatura).
 * `reason` estável para o front + status HTTP; o controller traduz (rotas WEB não viram
 * JSON sozinhas — convenção das duas portas de auth, CLAUDE.md).
 */
class FanclubException extends DomainException
{
    public const NOT_OPEN = 'not_open';

    public const INVALID = 'invalid';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const LIMIT = 'limit';

    public const SELF = 'self';

    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** O fã-clube da performer não está aberto / sem preço configurado → 422. */
    public static function notOpen(): self
    {
        return new self(self::NOT_OPEN, 422, 'O fã-clube desta performer não está disponível.');
    }

    /** Entrada inválida (preço fora do piso/passo/teto, VIP > público) → 422. */
    public static function invalid(string $message = 'Não foi possível salvar o fã-clube.'): self
    {
        return new self(self::INVALID, 422, $message);
    }

    /** Saldo insuficiente do membro ao assinar (o débito reverteu) → 422. */
    public static function insufficientBalance(): self
    {
        return new self(self::INSUFFICIENT_BALANCE, 422, 'Você não tem tokens suficientes para assinar.');
    }

    /** Teto de assinaturas ativas por membro atingido → 422. */
    public static function limit(): self
    {
        return new self(self::LIMIT, 422, 'Você atingiu o limite de assinaturas ativas.');
    }

    /** A performer tentando assinar o próprio fã-clube → 422. */
    public static function self(): self
    {
        return new self(self::SELF, 422, 'Você não pode assinar o próprio fã-clube.');
    }
}
