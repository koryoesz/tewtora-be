<?php

namespace App\Domains\Payment\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * An adapter call to the provider failed (docs/backend-engineering-standards.md
 * §3). The provider's own message goes to the log context only, never to
 * the caller.
 */
class PaymentProviderException extends AppException
{
    public function __construct(
        private readonly string $provider,
        private readonly string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The payment provider could not process this request. Please try again.', 0, $previous);
    }

    public function statusCode(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'payment_provider_error';
    }

    public function context(): array
    {
        return ['provider' => $this->provider, 'detail' => $this->detail];
    }
}
