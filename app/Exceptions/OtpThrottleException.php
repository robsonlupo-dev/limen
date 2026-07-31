<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Estourou o teto de 3 códigos/hora para um e-mail (OtpService::requestCode).
 *
 * O teto é aplicado sobre a STRING do e-mail ANTES do lookup do usuário, então
 * ele conta igual para e-mail existente e inexistente — é o que impede que o
 * próprio rate limit vire oráculo de enumeração ("este e-mail existe porque
 * eventualmente me bloqueia"). O chamador traduz para 429.
 */
class OtpThrottleException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 3600)
    {
        parent::__construct('Muitos pedidos de código. Aguarde alguns minutos e tente novamente.');
    }
}
