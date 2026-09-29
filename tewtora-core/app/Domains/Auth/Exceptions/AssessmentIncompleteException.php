<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Mirrors auth.assessments' own chk_learning_goals_present_if_submitted
 * CHECK — surfaced as a real validation error instead of letting the
 * database constraint reject the insert as an uncaught QueryException
 * (which the catch-all handler can only report as a generic 500).
 */
class AssessmentIncompleteException extends AppException
{
    public function __construct()
    {
        parent::__construct('At least one learning goal is required before an assessment can be submitted.');
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'assessment_incomplete';
    }
}
