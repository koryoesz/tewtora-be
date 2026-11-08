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
