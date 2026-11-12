<?php

namespace App\Console\Commands;

use App\Domains\Core\Services\TewtoraClassService;
use Illuminate\Console\Command;

/** Marks published classes whose last session has ended as completed. Scheduled hourly. */
class CompleteFinishedClasses extends Command
{
    protected $signature = 'classes:complete-finished';

    protected $description = 'Mark published classes whose last session has ended as completed.';

    public function handle(TewtoraClassService $service): int
    {
        $count = $service->completeFinished();

        if ($count > 0) {
            $this->info("Completed {$count} class(es).");
        }

        return self::SUCCESS;
    }
}
