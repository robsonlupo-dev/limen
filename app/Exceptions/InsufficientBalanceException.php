<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    // int (gasto de membro) OU string decimal (saldo/ganho fracionário da performer)
    // desde a economia de mensagem (19/08/2026).
    public function __construct(int|string $requested, int|string $available)
    {
        parent::__construct("Insufficient balance: requested {$requested}, available {$available}.");
    }
}
