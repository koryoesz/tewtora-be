<?php

use App\Domains\Recommendation\Http\Controllers\TeacherMatchController;
use Illuminate\Support\Facades\Route;

/*
| Recommendation routes. Only match accept/decline is built out this pass
| (docs/api-contract.md §9) — /matches (assessment results), the trial
| request flow's teacher-facing accept/decline, etc. are still outstanding.
*/

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/teachers/{teacherPublicId}/match-requests', [TeacherMatchController::class, 'index']);
    Route::post('/match-requests/{match:public_id}/accept', [TeacherMatchController::class, 'accept']);
    Route::post('/match-requests/{match:public_id}/decline', [TeacherMatchController::class, 'decline']);
});
