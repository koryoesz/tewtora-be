<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * The learner already has a confirmed seat in this class.
 */
class AlreadyEnrolledException extends AppException
{
    public function __construct()
    {
        parent::__construct('This learner is already enrolled in this class.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'already_enrolled';
    }
}
