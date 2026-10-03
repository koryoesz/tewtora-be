<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Onboarding spec: "Remove: refuse if the child has an active plan; keep
 * records 12 months." The 12-month-retention half is just the existing
 * SoftDeletes behavior (archive() never hard-deletes) — this exception is
 * the other half, the refusal itself.
 */
class LearnerHasActivePlanException extends AppException
{
    public function __construct()
    {
        parent::__construct('This child has an active plan. End or pause it before removing the profile.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'learner_has_active_plan';
    }
}
