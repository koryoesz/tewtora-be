<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/** Setting a PIN on a profile with no linked child login yet needs a username to create that login with. */
class ChildUsernameRequiredException extends AppException
{
    public function __construct()
    {
        parent::__construct("A username is required to set up this child's first sign-in.");
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'child_username_required';
    }
}
