<?php

namespace App\Exceptions;

use DomainException;

/**
 * Credenciais corretas, mas a conta não pode logar por estado de moderação.
 *
 * Lançada por AuthService::attemptLogin SÓ depois que a senha confere — nunca
 * para e-mail inexistente ou senha errada, que continuam devolvendo o `null`
 * genérico ("credenciais inválidas"). Ou seja: a mensagem específica só alcança
 * quem já provou a posse da conta, então distinguir `suspended` de `banned` aqui
 * não abre enumeração para um terceiro (senha errada nunca chega neste ponto).
 *
 * `suspended` é temporário/reversível; `banned` é encerramento permanente por
 * moderação. Cada porta de auth mapeia `status` para a sua resposta.
 */
class AccountBlockedException extends DomainException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct("Account login blocked: status={$status}.");
    }

    /** Mensagem para o usuário, específica por estado. */
    public function userMessage(): string
    {
        return match ($this->status) {
            'banned' => 'Sua conta foi permanentemente encerrada.',
            'suspended' => 'Sua conta está suspensa.',
            // Qualquer outro estado bloqueante futuro cai num texto neutro em
            // vez de vazar o rótulo interno.
            default => 'Não é possível acessar esta conta.',
        };
    }
}
