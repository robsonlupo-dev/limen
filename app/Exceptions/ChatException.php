<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falhas de domínio do chat. Carrega um `reason` estável (consumível pelo
 * frontend) além da mensagem legível.
 *
 * A máscara de opt-out NÃO usa esta exceção: enviar para um membro que optou por
 * sair precisa PARECER sucesso e não entregar nada (INTEREST_ANONYMITY_FLOOR.md,
 * "Consequência para o chat"). Ver ChatService::performerMessageFromInterest.
 */
class ChatException extends DomainException
{
    public const CHANNEL_NOT_OPEN = 'channel_not_open';

    public const NOT_A_PARTICIPANT = 'not_a_participant';

    public const CONVERSATION_ARCHIVED = 'conversation_archived';

    public const ACCESS_REQUIRED = 'access_required';

    public const CONTENT_BLOCKED = 'content_blocked';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function channelNotOpen(): self
    {
        return new self(
            self::CHANNEL_NOT_OPEN,
            'A conversa só abre depois que o membro desbloqueia o interesse.',
        );
    }

    public static function notAParticipant(): self
    {
        return new self(
            self::NOT_A_PARTICIPANT,
            'Você não participa desta conversa.',
        );
    }

    public static function conversationArchived(): self
    {
        return new self(
            self::CONVERSATION_ARCHIVED,
            'Esta conversa foi arquivada.',
        );
    }

    public static function accessRequired(): self
    {
        return new self(
            self::ACCESS_REQUIRED,
            'Seu acesso ao chat expirou. Renove por tokens ou assine um Círculo.',
        );
    }

    /**
     * Mensagem barrada pelo filtro de conteúdo.
     *
     * A mensagem é genérica de propósito e NÃO diz qual termo casou: apontar a
     * palavra entrega o mapa da evasão (basta reescrever trocando aquela e o
     * filtro para de ver qualquer coisa). O termo vai para o audit em HMAC.
     */
    public static function contentBlocked(): self
    {
        return new self(
            self::CONTENT_BLOCKED,
            'Mensagem não permitida pela política de uso da plataforma.',
        );
    }
}
