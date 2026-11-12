<?php

namespace App\Console\Commands;

use App\Domains\Core\Services\ClassEnrollmentService;
use Illuminate\Console\Command;

/** Puts seats held by unpaid enrolments back on sale once their hold runs out. Scheduled every minute. */
class ExpireClassSeatHolds extends Command
{
    protected $signature = 'classes:expire-holds';

    protected $description = 'Expire pending class enrolments whose payment hold has run out.';

    public function handle(ClassEnrollmentService $service): int
    {
        $count = $service->expireStaleHolds();

        if ($count > 0) {
            $this->info("Expired {$count} seat hold(s).");
        }

        return self::SUCCESS;
    }
}
