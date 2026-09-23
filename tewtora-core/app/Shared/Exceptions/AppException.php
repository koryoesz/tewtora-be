<?php

namespace App\Shared\Exceptions;

use Exception;

/**
 * backend-engineering-standards.md §3: one base exception, domain-specific
 * subclasses, and a single Handler (see Handler.php) that turns any of
 * them into the standard error envelope. Domain code just throws — it
 * never formats HTTP responses itself.
 */
abstract class AppException extends Exception
{
    abstract public function statusCode(): int;

    /** Machine-readable, stable, safe to branch on in a client, e.g. 'teacher_not_verified'. */
    abstract public function errorCode(): string;

    /** Extra fields for logging only — never sent to the client. */
    public function context(): array
    {
        return [];
    }
}
