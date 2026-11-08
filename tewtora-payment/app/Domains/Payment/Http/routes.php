<?php

use App\Domains\Payment\Http\Controllers\InternalCheckoutController;
use App\Domains\Payment\Http\Controllers\LedgerController;
use App\Domains\Payment\Http\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Payment routes. Plan checkout (quote/pay) is still not built — see
| docs/api-gap-analysis.md §7 for why (Payment can't resolve a teacher's
| rate_minor from Auth without a sync call that doesn't exist yet). Class
| enrolment checkouts are: Core starts them over /internal/checkouts.
*/

Route::middleware(['gateway.identity'])->group(function () {
    Route::get('/teachers/{teacherId}/ledger', [LedgerController::class, 'index']);
    Route::get('/teachers/{teacherId}/commission', [LedgerController::class, 'commission']);
});

// Service-to-service only (tewtora-core). Never exposed through the public gateway.
Route::prefix('internal')->middleware(['internal.token'])->group(function () {
    Route::post('/checkouts', [InternalCheckoutController::class, 'store']);
    Route::get('/checkouts/{payment:public_id}', [InternalCheckoutController::class, 'show']);
});

// Paystack calls this directly; authenticity comes from the signature, not a token.
Route::post('/webhooks/paystack', PaystackWebhookController::class);
