<?php

namespace App\Domains\Payment\Gateways;

final readonly class InitializedTransaction
{
    public function __construct(
        public string $reference,
        public string $checkoutUrl,
    ) {}
}
