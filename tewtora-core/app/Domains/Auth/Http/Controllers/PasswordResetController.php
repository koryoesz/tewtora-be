<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\ForgotPasswordRequest;
use App\Domains\Auth\Http\Requests\ResetPasswordRequest;
use App\Domains\Auth\Services\PasswordResetService;

/** POST /auth/forgot-password and /auth/reset-password — see PasswordResetService. */
class PasswordResetController
{
    public function __construct(
        private readonly PasswordResetService $service,
    ) {}

    /** 202 whether or not the email has an account — see PasswordResetService::sendResetLink(). */
    public function forgot(ForgotPasswordRequest $request)
    {
        $this->service->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => "If an account exists for that email, we've sent a link to reset the password.",
        ], 202);
    }

    public function reset(ResetPasswordRequest $request)
    {
        $this->service->reset($request->validated());

        return response()->json(status: 204);
    }
}
