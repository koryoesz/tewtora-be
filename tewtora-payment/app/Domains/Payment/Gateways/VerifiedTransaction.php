<?php

namespace App\Domains\Payment\Gateways;

use Carbon\CarbonImmutable;

/** A provider's own account of a transaction — from its verify API or a signed webhook. */
final readonly class VerifiedTransaction
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    /** Not final yet: still open, abandoned on the checkout page, etc. */
    public const PENDING = 'pending';

    public function __construct(
        public string $reference,
        public string $status,
        public int $amountMinor,
        public string $currencyCode,
        public ?string $channel = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $message = null,
    ) {}
}
