<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(int $requested, int $available)
    {
        parent::__construct("Insufficient balance: requested {$requested}, available {$available}.");
    }
}
