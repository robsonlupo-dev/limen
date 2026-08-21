<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falhas de domínio do chat da live (feat/live-room-console). Carrega um `reason`
 * estável para o front e o status HTTP que o controller devolve como JSON (rota
 * web — a exceção não vira JSON sozinha fora de api/*).
 */
class LiveChatException extends DomainException
{
    public const NOT_LIVE = 'not_live';

    public const MUTED = 'muted';

    public const CONTENT_BLOCKED = 'content_blocked';

    public const CONDUCT_BLOCKED = 'conduct_blocked';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notLive(): self
    {
        return new self(self::NOT_LIVE, 'A live não está no ar.', 409);
    }

    public static function muted(): self
    {
        return new self(self::MUTED, 'Você não pode enviar mensagens nesta live.', 403);
    }

    public static function contentBlocked(): self
    {
        return new self(
            self::CONTENT_BLOCKED,
            'Sua mensagem não pôde ser enviada: combinar programa mediante pagamento viola os Termos de Uso.',
        );
    }

    public static function conductBlocked(): self
    {
        return new self(
            self::CONDUCT_BLOCKED,
            'Sua mensagem não pôde ser enviada: ela fere a política de conduta da plataforma.',
        );
    }
}
