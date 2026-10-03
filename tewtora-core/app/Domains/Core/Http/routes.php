<?php

use App\Domains\Core\Http\Controllers\FeedbackController;
use App\Domains\Core\Http\Controllers\MessageThreadController;
use App\Domains\Core\Http\Controllers\MoveRequestController;
use App\Domains\Core\Http\Controllers\PlanController;
use App\Domains\Core\Http\Controllers\PricingController;
use App\Domains\Core\Http\Controllers\TeacherClassController;
use App\Domains\Core\Http\Controllers\TrialRequestController;
use Illuminate\Support\Facades\Route;

/*
| Main / Core routes. Outstanding: GET /plans/:id/candidate-slots (needs a
| teacher_availability read-model mirror that doesn't exist yet — see
| MoveRequestController's docblock), live session (§8), and any admin-only
| routes.
*/

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/learners/{learnerPublicId}/plans', [PlanController::class, 'index']);
    Route::get('/plans/{plan:public_id}', [PlanController::class, 'show']);
    Route::get('/plans/{plan:public_id}/next-sessions', [PlanController::class, 'nextSessions']);
    Route::get('/plans/{plan:public_id}/goals', [PlanController::class, 'goals']);
    Route::get('/plans/{plan:public_id}/history', [PlanController::class, 'history']);
    Route::post('/plans/{plan:public_id}/pause', [PlanController::class, 'pause']);
    Route::post('/plans/{plan:public_id}/rebook', [PlanController::class, 'rebook']);
    Route::delete('/plans/{plan:public_id}', [PlanController::class, 'destroy']);

    Route::post('/plans/{plan:public_id}/move-requests', [MoveRequestController::class, 'store']);
    Route::get('/move-requests', [MoveRequestController::class, 'forTeacher']);
    Route::get('/move-requests/{moveRequest:public_id}', [MoveRequestController::class, 'show']);
    Route::post('/move-requests/{moveRequest:public_id}/accept', [MoveRequestController::class, 'accept']);
    Route::post('/move-requests/{moveRequest:public_id}/decline', [MoveRequestController::class, 'decline']);
    Route::post('/move-requests/{moveRequest:public_id}/propose-alternate', [MoveRequestController::class, 'proposeAlternate']);
    Route::post('/move-requests/{moveRequest:public_id}/withdraw', [MoveRequestController::class, 'withdraw']);

    Route::post('/teachers/{teacherId}/trial-requests', [TrialRequestController::class, 'store']);
    // Teacher-side aggregate across every plan they teach — mirrors
    // /plans/:id/next-sessions and /history above (TeacherClassController's
    // own docblock). Raw public_id, not {teacher:public_id} binding — Core
    // resolves it through its own teacher_account_links read model.
    Route::get('/teachers/{teacherPublicId}/next-sessions', [TeacherClassController::class, 'nextSessions']);
    Route::get('/teachers/{teacherPublicId}/history', [TeacherClassController::class, 'history']);
    Route::get('/trial-requests/{trialRequest:public_id}', [TrialRequestController::class, 'show']);
    Route::delete('/trial-requests/{trialRequest:public_id}', [TrialRequestController::class, 'destroy']);
    Route::post('/trial-requests/{trialRequest:public_id}/respond', [TrialRequestController::class, 'respond']);

    Route::get('/sessions/{session:public_id}/feedback', [FeedbackController::class, 'show']);
    Route::put('/sessions/{session:public_id}/feedback/draft', [FeedbackController::class, 'saveDraft']);
    Route::post('/sessions/{session:public_id}/feedback', [FeedbackController::class, 'submit']);

    Route::get('/messages/threads', [MessageThreadController::class, 'index']);
    Route::get('/messages/threads/{thread:public_id}', [MessageThreadController::class, 'show']);
    Route::post('/messages/threads/{thread:public_id}/messages', [MessageThreadController::class, 'store']);
    Route::post('/messages/threads/{thread:public_id}/read', [MessageThreadController::class, 'markRead']);
    Route::post('/messages/threads/{thread:public_id}/report', [MessageThreadController::class, 'report']);

    Route::get('/pricing', [PricingController::class, 'index']);
});
