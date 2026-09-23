<?php

namespace App\Shared\Exceptions;

use Exception;

/** See tewtora-core's copy of this class for the full rationale — identical contract, separate service. */
abstract class AppException extends Exception
{
    abstract public function statusCode(): int;

    abstract public function errorCode(): string;

    public function context(): array
    {
        return [];
    }
}
