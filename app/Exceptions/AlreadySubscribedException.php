<?php

namespace App\Exceptions;

use RuntimeException;

class AlreadySubscribedException extends RuntimeException
{
    public function __construct(string $message = 'User already has an active subscription.')
    {
        parent::__construct($message);
    }
}
