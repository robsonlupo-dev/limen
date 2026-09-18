<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Recusa de regra de negócio da galeria do membro
 * (feat/member-gallery-and-profile). O controller traduz para erro de formulário
 * 422, nunca 500 — mesma disciplina do ImageProcessingException/CsamDetectedException.
 */
class MemberGalleryException extends RuntimeException
{
    public static function limitReached(): self
    {
        return new self('Você já atingiu o limite de '.\App\Models\MemberGalleryPhoto::MAX_ACTIVE.' fotos.');
    }
}
