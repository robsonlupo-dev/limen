<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Uma imagem no upload bateu com um hash conhecido de CSAM. Interrompe o upload
 * ANTES de qualquer gravação/serving. A mensagem ao usuário é DELIBERADAMENTE
 * genérica ("não pôde ser processada") — nunca confirma o motivo real, para não
 * dar sinal a quem testa a lista. O detalhe fica no log CRITICAL + na trilha.
 */
class CsamDetectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A imagem não pôde ser processada.');
    }
}
