<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

class TrialRequestExpiredException extends AppException
{
    public function __construct(private readonly int $trialRequestId)
    {
        parent::__construct('This trial request has expired.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'trial_request_expired';
    }

    public function context(): array
    {
        return ['trial_request_id' => $this->trialRequestId];
    }
}
