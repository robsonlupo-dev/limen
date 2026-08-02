<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no fluxo de Buscas Salvas (App\Services\SavedSearchService).
 *
 * Mesmo padrão de StoryException/ChatException: `reason` estável para o front, e
 * o controller traduz para HTTP. Como o Vue fala com rotas WEB, a exceção não
 * vira JSON sozinha — quem responde precisa de `response()->json()` explícito
 * (convenção das duas portas de auth, CLAUDE.md).
 */
class SavedSearchException extends DomainException
{
    /** Teto de buscas salvas atingido. Vira 422 no store. */
    public const LIMIT = 'limit';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * O membro já tem o máximo de buscas salvas.
     *
     * A mensagem DIZ o motivo e cita o teto: é dado dele sobre a própria conta,
     * não há oráculo a proteger, e ele precisa entender por que a gravação foi
     * recusada em vez de achar que é bug.
     */
    public static function limitReached(int $max): self
    {
        return new self(
            self::LIMIT,
            "Você já tem {$max} buscas salvas. Apague uma para salvar outra.",
        );
    }
}
