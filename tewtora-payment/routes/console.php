<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publishes PaymentSucceeded/PaymentFailed so tewtora-core can confirm class
// enrolments (it also confirms immediately via /internal/checkouts/{id} when
// the payer returns from Paystack; this is the path for everyone else).
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping();

// §7.4: catch payments whose webhook never arrived (and whose payer never
// came back to trigger a verify). Each settled one publishes like a webhook.
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping();
