<?php

use App\Domains\Auth\Http\Controllers\AdminAccountController;
use App\Domains\Auth\Http\Controllers\AdminAuditLogController;
use App\Domains\Auth\Http\Controllers\AdminSafeguardingController;
use App\Domains\Auth\Http\Controllers\AdminVerificationController;
use App\Domains\Auth\Http\Controllers\AssessmentController;
use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Auth\Http\Controllers\LearnerProfileController;
use App\Domains\Auth\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

/*
| Auth & Onboarding routes. Still outstanding: account registration (not in
| docs/api-contract.md §0's own endpoint list), §15/§16's matching-ops and
| stuck-money admin screens, teacher roster/diary (§12/§13).
*/

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/session', [AuthController::class, 'session']);
    Route::post('/auth/switch-profile', [AuthController::class, 'switchProfile']);

    Route::get('/learners', [LearnerProfileController::class, 'index']);
    Route::get('/learners/{learner:public_id}', [LearnerProfileController::class, 'show']);
    Route::post('/learners', [LearnerProfileController::class, 'store']);
    Route::patch('/learners/{learner:public_id}', [LearnerProfileController::class, 'update']);

    Route::get('/learners/{learner:public_id}/assessment', [AssessmentController::class, 'show']);
    Route::put('/learners/{learner:public_id}/assessment', [AssessmentController::class, 'saveDraft']);
    Route::post('/learners/{learner:public_id}/assessment/submit', [AssessmentController::class, 'submit']);

    Route::get('/teachers/{teacher:public_id}', [TeacherController::class, 'show']);
});

Route::prefix('internal')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/verification-applications', [AdminVerificationController::class, 'index']);
    Route::get('/verification-applications/{teacher:public_id}', [AdminVerificationController::class, 'show']);
    Route::post('/verification-applications/{teacher:public_id}/decide', [AdminVerificationController::class, 'decide']);

    Route::get('/safeguarding-incidents', [AdminSafeguardingController::class, 'index']);
    Route::get('/safeguarding-incidents/{incident:public_id}', [AdminSafeguardingController::class, 'show']);
    Route::post('/safeguarding-incidents/{incident:public_id}/close', [AdminSafeguardingController::class, 'close']);
    Route::post('/teachers/{teacher:public_id}/suspend-new-matches', [AdminSafeguardingController::class, 'suspendNewMatches']);

    Route::get('/accounts', [AdminAccountController::class, 'index']);
    Route::get('/accounts/{account:public_id}', [AdminAccountController::class, 'show']);
    Route::post('/accounts/{account:public_id}/act-as', [AdminAccountController::class, 'actAs']);

    Route::get('/audit-log', [AdminAuditLogController::class, 'index']);
});
