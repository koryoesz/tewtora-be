<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/** Rating a class before any of its days has finished: there's nothing to judge yet. */
class ClassNotRateableYetException extends AppException
{
    public function __construct()
    {
        parent::__construct('You can rate this class once its first class day has finished.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'class_not_rateable_yet';
    }
}
