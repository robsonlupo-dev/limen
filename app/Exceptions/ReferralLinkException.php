<?php

namespace App\Exceptions;

use Exception;

/**
 * Recusas na criação de link personalizado de indicação
 * (feat/referral-custom-links). Mensagens prontas para o usuário.
 *
 * Só DUAS mensagens de propósito: formato/limite são explícitos (ajudam quem
 * está de boa-fé), e TODO o resto — reservada, nome de criadora, contato,
 * conduta, já usado por outra pessoa — cai na mesma resposta genérica
 * (anti-oráculo, mesma disciplina do apelido do membro): a recusa nunca revela
 * se um nome existe, de quem é, ou por qual regra caiu.
 */
class ReferralLinkException extends Exception
{
    public static function format(): self
    {
        return new self('Use de 3 a 20 caracteres: letras minúsculas, números e hífen.');
    }

    public static function limit(int $max): self
    {
        return new self("Você já criou seus {$max} links personalizados.");
    }

    public static function unavailable(): self
    {
        return new self('Desculpe, este link já existe. Por favor, tente usar outro.');
    }
}
