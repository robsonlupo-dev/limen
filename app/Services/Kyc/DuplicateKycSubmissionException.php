<?php

namespace App\Services\Kyc;

use RuntimeException;

/**
 * Já existe verificação ativa (pending/review/approved) para o usuário.
 * Subclasse de RuntimeException para as portas capturarem ESTE caso sem
 * engolir qualquer outra RuntimeException do fluxo de submit.
 */
class DuplicateKycSubmissionException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Você já possui uma verificação ativa ou pendente.');
    }
}
