<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no fluxo de chamada privada 1:1 (App\Services\CallService,
 * Sprint 15). Mesmo padrão do StoryException/ChatException: `reason` estável para
 * o front + status HTTP, e o controller traduz — como o Vue fala com rotas WEB, a
 * exceção não vira JSON sozinha (convenção das duas portas de auth, CLAUDE.md).
 *
 * ── Uniformidade de 404 (anti-oráculo) ──────────────────────────────────────
 * `notFound()` cobre "não existe", "não é sua chamada" (IDOR) e "estado incompatível
 * com o ator". Os três devolvem o MESMO 404, senão o par de respostas viraria
 * oráculo de existência da chamada de terceiro (mesma disciplina de Favoritos/
 * SavedSearch/MemberNote). `gone()` (410) é o encerramento — o front desconecta.
 */
class CallException extends DomainException
{
    public const NOT_FOUND = 'not_found';

    public const GONE = 'gone';

    public const CONFLICT = 'conflict';

    public const UNAVAILABLE = 'unavailable';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const INVALID_PRICE = 'invalid_price';

    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Chamada inexistente, de terceiro (IDOR) ou em estado incompatível → 404. */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, 'Chamada não encontrada.');
    }

    /** A chamada já foi encerrada/expirada — o front desconecta. */
    public static function gone(): self
    {
        return new self(self::GONE, 410, 'Esta chamada foi encerrada.');
    }

    /** Performer (ou membro) já está em outra chamada — 1:1 é exclusivo. */
    public static function conflict(): self
    {
        return new self(self::CONFLICT, 409, 'Já existe uma chamada em andamento.');
    }

    /** Já há uma solicitação de upgrade pendente (group show → 1:1). */
    public static function upgradePending(): self
    {
        return new self(self::CONFLICT, 409, 'Já existe uma solicitação pendente.');
    }

    /** O group show atingiu o teto de participantes. */
    public static function full(): self
    {
        return new self(self::CONFLICT, 409, 'O grupo está cheio.');
    }

    /** A performer não aceita chamadas (sem preço configurado) ou está fora do ar. */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 422, 'Esta performer não está disponível para chamadas.');
    }

    /** O membro não tem saldo para o primeiro minuto. */
    public static function insufficientBalance(): self
    {
        return new self(self::INSUFFICIENT_BALANCE, 422, 'Saldo insuficiente para iniciar a chamada.');
    }

    /** Preço por minuto fora do piso/passo (validação da configuração da performer). */
    public static function invalidPrice(): self
    {
        return new self(self::INVALID_PRICE, 422, 'Preço por minuto inválido.');
    }
}
