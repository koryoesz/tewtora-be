<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Nothing built out yet this pass — checkout/quote/pay (docs/api-contract.md
| §7) is blocked on a real decision for how Payment resolves a teacher's
| rate_minor from Auth's database without a live cross-schema join (see
| docs/api-gap-analysis.md §7 and CLAUDE.md's cross-domain hard rule).
| Everything else in this app so far (the SessionFeedbackFiled consumer)
| is queue-driven, not HTTP-driven, so it needs no route.
|
*/

Route::prefix('v1')->group(function () {
    require app_path('Domains/Payment/Http/routes.php');
});
