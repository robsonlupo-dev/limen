<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio na galeria de fotos do perfil (App\Services\PerformerPhotoService).
 *
 * Mesmo padrão do StoryException: `reason` estável para o front, e o chamador
 * traduz para HTTP. Como o Vue fala com rotas WEB, a exceção não vira JSON
 * sozinha — quem responde precisa de `response()->json()` explícito (convenção
 * das duas portas de auth, CLAUDE.md).
 */
class PhotoGalleryException extends DomainException
{
    /** Teto de 6 fotos atingido (PerformerProfile::MAX_PHOTOS). → 422 */
    public const CAP_REACHED = 'cap_reached';

    /** Foto (ou ordem) que não pertence a quem pediu. → 403 */
    public const NOT_OWNER = 'not_owner';

    /** Reordenação com um conjunto de ids que não bate com a galeria. → 422 */
    public const INVALID_ORDER = 'invalid_order';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function capReached(int $max): self
    {
        return new self(
            self::CAP_REACHED,
            "Você já atingiu o limite de {$max} fotos. Apague uma para adicionar outra.",
        );
    }

    /**
     * Foto de outra performer (porta do delete) — mensagem uniforme com "não
     * disponível", para a resposta não confirmar que aquele id existe e é de
     * outra pessoa.
     */
    public static function notOwner(): self
    {
        return new self(
            self::NOT_OWNER,
            'Esta foto não está disponível.',
        );
    }

    /**
     * A lista de ids da reordenação não corresponde exatamente à galeria da
     * performer (id faltando, sobrando ou de outra pessoa). Recusa em bloco: uma
     * reordenação parcial deixaria posições inconsistentes.
     */
    public static function invalidOrder(): self
    {
        return new self(
            self::INVALID_ORDER,
            'A ordem enviada não corresponde às suas fotos. Recarregue a página e tente de novo.',
        );
    }
}
