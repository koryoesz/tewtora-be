<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/** docs/api-contract.md §14: "Reject decision: 'approved' server-side if any check is not 'confirmed' — this must not be a client-side-only disabled button." */
class VerificationChecksIncompleteException extends AppException
{
    public function __construct(private readonly int $teacherId)
    {
        parent::__construct('Every verification check must be approved before this teacher can be approved.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'verification_checks_incomplete';
    }

    public function context(): array
    {
        return ['teacher_id' => $this->teacherId];
    }
}
