<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no agendamento de chamada (App\Services\CallReservationService,
 * feat/scheduled-call-v1). Mesmo padrão de CallException/StoryException: `reason`
 * estável para o front + status HTTP, e o controller traduz (o Vue fala com rotas
 * WEB — a exceção não vira JSON sozinha; convenção das duas portas de auth, CLAUDE.md).
 *
 * ── Uniformidade de 404 (anti-oráculo) ──────────────────────────────────────
 * `notFound()` cobre "não existe", "não é sua reserva" (IDOR) e "estado incompatível
 * com o ator". Os três devolvem o MESMO 404, senão o par de respostas viraria
 * oráculo de existência da reserva de terceiro (disciplina de Favoritos/SavedSearch/
 * MemberNote/CallException).
 */
class CallReservationException extends DomainException
{
    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const SLOT_UNAVAILABLE = 'slot_unavailable';

    public const LIMIT = 'limit';

    public const INVALID_TIME = 'invalid_time';

    public const NOT_JOINABLE = 'not_joinable';

    public const GONE = 'gone';

    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Reserva inexistente, de terceiro (IDOR) ou em estado incompatível → 404. */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, 'Reserva não encontrada.');
    }

    /** A performer não aceita chamadas (sem preço) ou está fora do ar → 422. */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 422, 'Esta performer não está disponível para agendamento.');
    }

    /** O membro não tem saldo para o depósito (preço de 1 minuto) → 422. */
    public static function insufficientBalance(): self
    {
        return new self(self::INSUFFICIENT_BALANCE, 422, 'Saldo insuficiente para o depósito do agendamento.');
    }

    /** O horário escolhido colide com outro agendamento da performer (+buffer) → 409. */
    public static function slotUnavailable(): self
    {
        return new self(self::SLOT_UNAVAILABLE, 409, 'Este horário não está disponível.');
    }

    /** O membro atingiu o teto de agendamentos ativos → 422. */
    public static function limit(): self
    {
        return new self(self::LIMIT, 422, 'Você atingiu o limite de agendamentos ativos.');
    }

    /** Horário fora da antecedência mínima/máxima permitida → 422. */
    public static function invalidTime(): self
    {
        return new self(self::INVALID_TIME, 422, 'Horário fora da janela permitida para agendamento.');
    }

    /** Não é possível entrar agora (fora da janela, performer ainda não entrou) → 409. */
    public static function notJoinable(): self
    {
        return new self(self::NOT_JOINABLE, 409, 'A chamada não está disponível para entrar agora.');
    }

    /** A reserva já foi resolvida (cancelada/no-show/concluída) → 410. */
    public static function gone(): self
    {
        return new self(self::GONE, 410, 'Este agendamento não está mais ativo.');
    }
}
