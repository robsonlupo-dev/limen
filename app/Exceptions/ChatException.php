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

    public const CONDUCT_BLOCKED = 'conduct_blocked';

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
     * Mensagem barrada por sugerir transação fora da plataforma ou encontro
     * mediante pagamento (TIPO 1).
     *
     * A mensagem DIZ o que foi violado. A versão anterior era genérica para
     * "não entregar o mapa da evasão", e a revisão de segurança mostrou que
     * isso nunca valeu: a lista está no repo e o remetente distingue as
     * categorias pela resposta em duas tentativas. Sobrava só o usuário de
     * boa-fé sem saber o que fazer — pagou pelo acesso e levou uma vaguidade.
     * O termo exato continua fora da resposta; vai para o audit em HMAC.
     */
    public static function legalRiskBlocked(): self
    {
        return new self(
            self::CONTENT_BLOCKED,
            'Esta mensagem não é permitida pois sugere transação fora da plataforma '
            .'ou encontro mediante pagamento, o que viola os Termos de Uso.',
        );
    }

    /** Mensagem barrada por conduta abusiva — ameaça ou insulto direcionado (TIPO 2). */
    public static function conductBlocked(): self
    {
        return new self(
            self::CONDUCT_BLOCKED,
            'Esta mensagem foi bloqueada por violar nossa política de conduta.',
        );
    }
}
