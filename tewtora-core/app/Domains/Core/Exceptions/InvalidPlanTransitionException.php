<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

class InvalidPlanTransitionException extends AppException
{
    public function __construct(private readonly int $planId, private readonly string $attempted, private readonly string $currentStatus)
    {
        parent::__construct("Cannot {$attempted} a plan that is currently {$currentStatus}.");
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_plan_transition';
    }

    public function context(): array
    {
        return ['plan_id' => $this->planId, 'attempted' => $this->attempted, 'current_status' => $this->currentStatus];
    }
}
