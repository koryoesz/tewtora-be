<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Distinct from InvalidCredentialsException on purpose: a parent paused
 * this child's sign-in (LearnerProfileController::pauseSignIn), so the
 * frontend can show "sign-in paused" rather than "wrong PIN" — unlike
 * InvalidCredentialsException, it's fine here to be specific, since the
 * username/PIN pair was actually correct.
 */
class ChildSignInPausedException extends AppException
{
    public function __construct()
    {
        parent::__construct('Sign-in has been paused for this profile.');
    }

    public function statusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'child_sign_in_paused';
    }
}
