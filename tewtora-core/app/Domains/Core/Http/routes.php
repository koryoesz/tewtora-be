<?php

use App\Domains\Core\Http\Controllers\AdminPaymentController;
use App\Domains\Core\Http\Controllers\AdminTewtoraClassController;
use App\Domains\Core\Http\Controllers\ClassEnrollmentController;
use App\Domains\Core\Http\Controllers\FeedbackController;
use App\Domains\Core\Http\Controllers\MessageThreadController;
use App\Domains\Core\Http\Controllers\MoveRequestController;
use App\Domains\Core\Http\Controllers\PlanController;
use App\Domains\Core\Http\Controllers\PricingController;
use App\Domains\Core\Http\Controllers\TeacherClassController;
use App\Domains\Core\Http\Controllers\TewtoraClassController;
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

    // Public_id, not the numeric id docs/api-contract.md §3 originally used
    // — the browse endpoint (GET /teachers, Auth domain) only ever hands
    // out public_id, so requiring a numeric id here was the exact "no way
    // to get one" gap docs/needed-endpoints-trial-requests.md flagged.
    // Resolved via teacher_account_links, same as TeacherClassController.
    Route::post('/teachers/{teacherPublicId}/trial-requests', [TrialRequestController::class, 'store']);
    // Teacher-side aggregate across every plan they teach — mirrors
    // /plans/:id/next-sessions and /history above (TeacherClassController's
    // own docblock). Raw public_id, not {teacher:public_id} binding — Core
    // resolves it through its own teacher_account_links read model.
    Route::get('/teachers/{teacherPublicId}/next-sessions', [TeacherClassController::class, 'nextSessions']);
    Route::get('/teachers/{teacherPublicId}/history', [TeacherClassController::class, 'history']);
    Route::get('/teachers/{teacherPublicId}/plans', [TeacherClassController::class, 'plans']);
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

    // Tewtora classes — tewtora-fe docs/needed-endpoints-tewtora-classes.md.
    // Families browse, enrol and pay; the class teacher sees the roster.
    // /classes/sessions must stay above /classes/{class}.
    Route::get('/classes', [TewtoraClassController::class, 'index']);
    Route::get('/classes/sessions', [TewtoraClassController::class, 'schedule']);
    Route::get('/classes/{class:public_id}', [TewtoraClassController::class, 'show']);
    Route::get('/classes/{class:public_id}/sessions', [TewtoraClassController::class, 'sessions']);
    Route::get('/classes/{class:public_id}/roster', [TewtoraClassController::class, 'roster']);
    Route::post('/classes/{class:public_id}/rating', [TewtoraClassController::class, 'rate']);
    // Both spellings: the frontend spec says "enrolments". Throttled: each call can start a Paystack checkout.
    Route::post('/classes/{class:public_id}/enrolments', [TewtoraClassController::class, 'enroll'])->middleware('throttle:20,1');
    Route::post('/classes/{class:public_id}/enrollments', [TewtoraClassController::class, 'enroll'])->middleware('throttle:20,1');
    Route::get('/class-enrollments', [ClassEnrollmentController::class, 'index']);
    Route::get('/learners/{learnerPublicId}/class-enrollments', [ClassEnrollmentController::class, 'forLearner']);
    // By Paystack's ?reference= on the return page; must stay above /class-enrollments/{enrollment}.
    Route::post('/class-enrollments/verify-payment', [ClassEnrollmentController::class, 'verifyPaymentByReference'])->middleware('throttle:30,1');
    Route::get('/class-enrollments/{enrollment:public_id}', [ClassEnrollmentController::class, 'show']);
    Route::post('/class-enrollments/{enrollment:public_id}/verify-payment', [ClassEnrollmentController::class, 'verifyPayment'])->middleware('throttle:30,1');
});

// Tewtora classes — admin management.
Route::prefix('internal')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/classes', [AdminTewtoraClassController::class, 'index']);
    Route::post('/classes', [AdminTewtoraClassController::class, 'store']);
    Route::get('/classes/{class:public_id}', [AdminTewtoraClassController::class, 'show']);
    Route::patch('/classes/{class:public_id}', [AdminTewtoraClassController::class, 'update']);
    Route::patch('/classes/{class:public_id}/sessions/{day}', [AdminTewtoraClassController::class, 'updateSession'])->whereNumber('day');
    Route::post('/classes/{class:public_id}/publish', [AdminTewtoraClassController::class, 'publish']);
    Route::post('/classes/{class:public_id}/cancel', [AdminTewtoraClassController::class, 'cancel']);
    Route::post('/classes/{class:public_id}/image', [AdminTewtoraClassController::class, 'uploadImage']);
    Route::delete('/classes/{class:public_id}/image', [AdminTewtoraClassController::class, 'deleteImage']);
    Route::get('/classes/{class:public_id}/enrollments', [AdminTewtoraClassController::class, 'roster']);

    // Staff Payments page — tewtora-fe docs/needed-endpoints-admin-payments.md.
    Route::get('/payments', [AdminPaymentController::class, 'index']);
    Route::get('/payments/summary', [AdminPaymentController::class, 'summary']);
});
