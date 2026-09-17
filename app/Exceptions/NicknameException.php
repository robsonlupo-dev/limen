<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Recusa de apelido (feat/member-nickname). Carrega um `reason` estável (para o
 * front/testes) e uma mensagem em pt-BR já pronta. As mensagens são
 * DELIBERADAMENTE genéricas onde revelar o motivo viraria oráculo: apelido já em
 * uso e apelido igual a nome de performer devolvem a MESMA "não está disponível"
 * — nunca confirmam que existe um membro/performer com aquele nome.
 */
class NicknameException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function length(): self
    {
        return new self('length', 'O apelido deve ter entre 3 e 20 caracteres.');
    }

    public static function contact(): self
    {
        return new self('contact', 'O apelido não pode conter telefone, e-mail, link ou rede social.');
    }

    public static function reserved(): self
    {
        return new self('reserved', 'Esse apelido não é permitido.');
    }

    public static function conduct(): self
    {
        return new self('conduct', 'Esse apelido não é permitido.');
    }

    // Duplicado E colisão com nome de performer: MESMA mensagem — não revelar que
    // existe alguém com o nome (anti-oráculo / anti-personificação).
    public static function unavailable(): self
    {
        return new self('unavailable', 'Esse apelido não está disponível.');
    }

    public static function cooldown(int $daysLeft): self
    {
        $d = max(1, $daysLeft);

        return new self('cooldown', "Você só pode trocar o apelido uma vez a cada 7 dias. Tente de novo em {$d} ".($d === 1 ? 'dia' : 'dias').'.');
    }
}
