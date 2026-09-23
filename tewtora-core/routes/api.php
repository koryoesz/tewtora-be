<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| backend-engineering-standards.md §10: versioned from day one. Each
| domain's routes live in its own file under app/Domains/{X}/Http, included
| below rather than defined inline here, so the domain boundary holds for
| routing too.
|
*/

Route::prefix('v1')->group(function () {
    require app_path('Domains/Auth/Http/routes.php');
    require app_path('Domains/Recommendation/Http/routes.php');
    require app_path('Domains/Core/Http/routes.php');
});
