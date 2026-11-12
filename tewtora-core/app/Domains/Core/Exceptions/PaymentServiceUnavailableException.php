<?php

namespace App\Domains\Core\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * Core couldn't reach the Payment service, or it couldn't reach the provider. The detail is in the
 * log context only, never sent to the client.
 */
class PaymentServiceUnavailableException extends AppException
{
    public function __construct(private readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct('We couldn\'t start the payment right now. Please try again in a moment.', 0, $previous);
    }

    public function statusCode(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'payment_unavailable';
    }

    public function context(): array
    {
        return ['detail' => $this->detail];
    }
}
