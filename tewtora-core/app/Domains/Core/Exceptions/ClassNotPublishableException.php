<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Publishing a class that isn't ready: no sessions, a first session in
 * the past, or no meeting link. The message lists every problem at once,
 * so the admin can fix them all in one go.
 */
class ClassNotPublishableException extends AppException
{
    /** @param  list<string>  $problems */
    public function __construct(private readonly array $problems)
    {
        parent::__construct('This class can\'t be published yet: '.implode(' ', $problems));
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'class_not_publishable';
    }

    public function context(): array
    {
        return ['problems' => $this->problems];
    }
}
