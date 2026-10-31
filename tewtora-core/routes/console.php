<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CLAUDE.md: "run every few seconds via the scheduler or a dedicated
// worker." Every-minute is Laravel's own scheduler floor; a dedicated
// worker loop is what an actual sub-minute cadence needs in production.
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping();

// Failed jobs (e.g. an email Resend kept rejecting) stay retryable with
// `queue:retry` for 30 days, then are pruned so the table doesn't grow forever.
Schedule::command('queue:prune-failed --hours=720')->daily();
