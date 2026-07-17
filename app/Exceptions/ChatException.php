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
}
