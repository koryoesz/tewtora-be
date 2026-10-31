<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Only reachable when two sign-ups for the same email race past
 * RegisterRequest's unique rule and the second one hits
 * ux_accounts_email_live. The ordinary duplicate case is a
 * validation_failed on `email`.
 */
class EmailAlreadyRegisteredException extends AppException
{
    public function __construct()
    {
        parent::__construct('An account with this email already exists.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'email_already_registered';
    }
}
