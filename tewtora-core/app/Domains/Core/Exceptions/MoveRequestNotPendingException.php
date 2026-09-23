<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

class MoveRequestNotPendingException extends AppException
{
    public function __construct(private readonly int $moveRequestId, private readonly string $currentStatus)
    {
        parent::__construct("This move request is already {$currentStatus}.");
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'move_request_not_pending';
    }

    public function context(): array
    {
        return ['move_request_id' => $this->moveRequestId, 'current_status' => $this->currentStatus];
    }
}
