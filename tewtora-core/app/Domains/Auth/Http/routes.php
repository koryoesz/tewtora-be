<?php

use App\Domains\Auth\Http\Controllers\AdminAccountController;
use App\Domains\Auth\Http\Controllers\AdminAuditLogController;
use App\Domains\Auth\Http\Controllers\AdminSafeguardingController;
use App\Domains\Auth\Http\Controllers\AdminVerificationController;
use App\Domains\Auth\Http\Controllers\AssessmentController;
use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Auth\Http\Controllers\CurriculumController;
use App\Domains\Auth\Http\Controllers\LearnerProfileController;
use App\Domains\Auth\Http\Controllers\SubjectController;
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

    // Lets a curriculum/subject picker (e.g. "Add a child", teacher
    // onboarding) read the real seeded set instead of hardcoding codes —
    // see CurriculumSeeder/SubjectSeeder's docblocks.
    Route::get('/curricula', [CurriculumController::class, 'index']);
    Route::get('/subjects', [SubjectController::class, 'index']);

    Route::get('/learners', [LearnerProfileController::class, 'index']);
    Route::get('/learners/{learner:public_id}', [LearnerProfileController::class, 'show']);
    Route::post('/learners', [LearnerProfileController::class, 'store']);
    Route::patch('/learners/{learner:public_id}', [LearnerProfileController::class, 'update']);
    Route::post('/learners/{learner:public_id}/pin', [LearnerProfileController::class, 'setPin']);
    Route::post('/learners/{learner:public_id}/archive', [LearnerProfileController::class, 'archive']);
    // Raw string, not {learner:public_id} binding — that binding excludes
    // soft-deleted rows, which is exactly the one this restores.
    Route::post('/learners/{learnerPublicId}/restore', [LearnerProfileController::class, 'restore']);
    // Disables/re-enables the child's own username+PIN login
    // (AuthSessionService::loginChild) — independent of archive/restore
    // above, see 2024_02_01_000104's docblock.
    Route::post('/learners/{learner:public_id}/pause-sign-in', [LearnerProfileController::class, 'pauseSignIn']);
    Route::post('/learners/{learner:public_id}/resume-sign-in', [LearnerProfileController::class, 'resumeSignIn']);

    Route::get('/learners/{learner:public_id}/assessment', [AssessmentController::class, 'show']);
    Route::put('/learners/{learner:public_id}/assessment', [AssessmentController::class, 'saveDraft']);
    Route::post('/learners/{learner:public_id}/assessment/submit', [AssessmentController::class, 'submit']);

    // Unranked, filtered browse list of verified teachers —
    // docs/needed-endpoints-browse-matching.md §1. Not the ranked /matches
    // §2 asks for eventually; see BrowseTeachersRequest's docblock.
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/teachers/{teacher:public_id}', [TeacherController::class, 'show']);
    // A teacher editing their own subjects/curricula/levels/format/rate/
    // years/availability/bio — additive to the GET shape above, see
    // UpdateTeacherProfileRequest's docblock for why this never touches
    // verification_status.
    Route::patch('/teachers/{teacher:public_id}', [TeacherController::class, 'update']);
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
