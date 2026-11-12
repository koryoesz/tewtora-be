<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Enrolling in a class that isn't published, or whose last class day has already finished.
 */
class ClassNotOpenForEnrollmentException extends AppException
{
    public function __construct()
    {
        parent::__construct('This class isn\'t open for enrolment.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'class_not_open';
    }
}
