<?php

namespace App\Console\Commands;

use App\Domains\Core\Services\PaymentViewService;
use Illuminate\Console\Command;

/**
 * One-off backfill of core.payment_view from class enrolments that started a
 * checkout before the view existed; safe to re-run as a repair (idempotent,
 * never moves a row's status backwards). Failures and channels only arrive
 * through events, so a backfilled unpaid payment shows as pending.
 */
class SyncPaymentView extends Command
{
    protected $signature = 'payments:sync-view';

    protected $description = 'Backfill/repair the staff payments view from class enrolments.';

    public function handle(PaymentViewService $service): int
    {
        $this->info('Synced '.$service->syncClassEnrollments().' payment(s).');

        return self::SUCCESS;
    }
}
