<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Payment says the payment being verified was for something other than this
 * class enrolment (another purpose, or another enrolment). Never confirms a
 * seat on it; logged as critical, because it can only mean a bug or tampering.
 */
class PaymentDoesNotMatchEnrollmentException extends AppException
{
    public function __construct(private readonly array $details = [])
    {
        parent::__construct('This payment doesn\'t belong to this class enrolment. Please contact support.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'payment_mismatch';
    }

    public function context(): array
    {
        return $this->details;
    }
}
