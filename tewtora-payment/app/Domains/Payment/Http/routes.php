<?php

use App\Domains\Payment\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

/*
| Payment routes. Checkout (quote/pay) is not built — see
| docs/api-gap-analysis.md §7 for why (Payment can't resolve a teacher's
| rate_minor from Auth without a sync call that doesn't exist yet).
*/

Route::middleware(['gateway.identity'])->group(function () {
    Route::get('/teachers/{teacherId}/ledger', [LedgerController::class, 'index']);
    Route::get('/teachers/{teacherId}/commission', [LedgerController::class, 'commission']);
});
