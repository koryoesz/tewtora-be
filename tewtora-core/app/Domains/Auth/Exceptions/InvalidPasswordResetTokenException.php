<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

class InvalidPasswordResetTokenException extends AppException
{
    public function __construct()
    {
        // Covers a wrong, already-used or expired token, and an email with
        // no account — deliberately indistinguishable, same reasoning as
        // InvalidCredentialsException.
        parent::__construct('This reset link is invalid or has expired. Please request a new one.');
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_reset_token';
    }
}
