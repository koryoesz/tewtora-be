<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

class InvalidCredentialsException extends AppException
{
    public function __construct()
    {
        parent::__construct('Incorrect email or password.');
    }

    public function statusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }
}
