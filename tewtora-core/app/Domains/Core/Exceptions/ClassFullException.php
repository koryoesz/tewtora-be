<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Every seat is taken by a confirmed enrolment or a pending one whose payment hold hasn't run out.
 */
class ClassFullException extends AppException
{
    public function __construct()
    {
        parent::__construct('This class is full.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'class_full';
    }
}
