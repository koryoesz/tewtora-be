<?php

namespace App\Console\Commands;

use App\Domains\Payment\Services\CheckoutService;
use Illuminate\Console\Command;

/**
 * docs/microservices-architecture.md §7.4: the safety net under the webhook.
 * Re-verifies payments that aren't `success` yet, created between
 * --max-age hours and --min-age minutes ago. --min-age leaves a payer who
 * is still on the checkout page alone; --max-age stops re-checking checkouts
 * that were simply abandoned. Anything that settles is published through
 * the outbox like a webhook would be, so Core needs nothing extra.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
        {--min-age=10 : Only payments at least this many minutes old}
        {--max-age=48 : Only payments at most this many hours old}
        {--limit=200 : At most this many provider calls per run}';

    protected $description = 'Re-verify recent unsettled payments with the provider, in case a webhook was missed.';

    public function handle(CheckoutService $checkouts): int
    {
        $result = $checkouts->reconcile(
            now()->subHours(max(1, (int) $this->option('max-age'))),
            now()->subMinutes(max(0, (int) $this->option('min-age'))),
            max(1, (int) $this->option('limit')),
        );

        $this->info("Checked {$result['checked']}, settled {$result['settled']}, errors {$result['errors']}.");

        return self::SUCCESS;
    }
}
