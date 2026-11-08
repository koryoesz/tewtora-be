<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Changing price_minor/currency_code after any enrolment exists: people may already have paid the old price.
 */
class ClassFeeLockedException extends AppException
{
    public function __construct()
    {
        parent::__construct('The price can\'t change once someone has enrolled.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'class_fee_locked';
    }
}
