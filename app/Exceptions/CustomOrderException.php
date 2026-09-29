<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio na encomenda sob medida (App\Services\CustomOrderService, Onda 4
 * §4.3). `reason` estável para o front + status HTTP; o controller traduz (rotas WEB não
 * viram JSON sozinhas — convenção das duas portas de auth, CLAUDE.md).
 *
 * ── Uniformidade de 404 (anti-oráculo) ──────────────────────────────────────
 * `notFound()` cobre "não existe", "não é sua encomenda" (IDOR) e "estado incompatível
 * com o ator" — os três devolvem o MESMO 404, senão o par de respostas viraria oráculo
 * da encomenda de terceiro (disciplina de CallReservationException).
 */
class CustomOrderException extends DomainException
{
    public const NOT_FOUND = 'not_found';

    public const INVALID = 'invalid';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const LIMIT = 'limit';

    public const GONE = 'gone';

    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Encomenda inexistente, de terceiro (IDOR) ou em estado incompatível → 404. */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, 'Encomenda não encontrada.');
    }

    /** Entrada inválida (preço fora do piso/passo/teto, descrição, peça não pronta) → 422. */
    public static function invalid(string $message = 'Não foi possível processar a encomenda.'): self
    {
        return new self(self::INVALID, 422, $message);
    }

    /** Saldo insuficiente do membro no ACEITE (o débito do escrow reverteu) → 422. */
    public static function insufficientBalance(): self
    {
        return new self(self::INSUFFICIENT_BALANCE, 422, 'O membro não tem saldo para esta encomenda.');
    }

    /** Teto de encomendas ativas (por membro ou por par) atingido → 422. */
    public static function limit(): self
    {
        return new self(self::LIMIT, 422, 'Limite de encomendas em aberto atingido.');
    }

    /** Estado terminal / janela vencida (ex.: contestar depois do prazo) → 410. */
    public static function gone(): self
    {
        return new self(self::GONE, 410, 'Esta encomenda não está mais nesse estado.');
    }
}
