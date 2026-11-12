<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Lowering capacity below the seats already taken.
 */
class ClassCapacityBelowEnrollmentsException extends AppException
{
    public function __construct()
    {
        parent::__construct('Capacity can\'t be lower than the number of learners already holding a seat.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'capacity_below_enrollments';
    }
}
