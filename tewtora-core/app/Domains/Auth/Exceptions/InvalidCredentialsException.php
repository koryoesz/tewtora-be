<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

class InvalidCredentialsException extends AppException
{
    public function __construct()
    {
        // Covers both credential shapes AuthSessionService::login() accepts
        // (email+password, username+pin) — deliberately doesn't say which
        // field was wrong, or whether the account exists at all.
        parent::__construct('Incorrect sign-in details.');
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
