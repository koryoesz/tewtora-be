<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

class TrialRequestNotPendingException extends AppException
{
    public function __construct(private readonly int $trialRequestId, private readonly string $currentStatus)
    {
        parent::__construct("This trial request is already {$currentStatus}.");
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'trial_request_not_pending';
    }

    public function context(): array
    {
        return ['trial_request_id' => $this->trialRequestId, 'current_status' => $this->currentStatus];
    }
}
