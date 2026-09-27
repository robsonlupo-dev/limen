<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no fluxo de Destaques (App\Services\StoryHighlightService).
 * Mesmo padrão do StoryException: `reason` estável, o chamador traduz para HTTP
 * (rota WEB → precisa de response()->json() explícito).
 */
class HighlightException extends DomainException
{
    public const NOT_OWNER = 'not_owner';

    public const LIMIT_REACHED = 'limit_reached';

    public const NOT_PUBLIC = 'not_public';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Coleção/story de outra performer (recusa uniforme, não confirma existência). */
    public static function notOwner(): self
    {
        return new self(self::NOT_OWNER, 'Este destaque não está disponível.');
    }

    public static function collectionLimit(int $max): self
    {
        return new self(self::LIMIT_REACHED, "Você já tem {$max} coleções de destaque. Apague uma para criar outra.");
    }

    public static function itemLimit(int $max): self
    {
        return new self(self::LIMIT_REACHED, "Esta coleção já tem {$max} itens. Remova um para adicionar outro.");
    }

    /** Só story público pode virar destaque (vitrine pública; paywall intacto). */
    public static function notPublic(): self
    {
        return new self(self::NOT_PUBLIC, 'Só stories públicos podem entrar num destaque.');
    }
}
