<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Core\Http\Resources\ClassEnrollmentResource;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Services\ClassEnrollmentService;
use Illuminate\Http\Request;

/** /class-enrollments — a family's own enrolments, and the "I've paid" check on return from Paystack. */
class ClassEnrollmentController
{
    public function __construct(
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
        private readonly ClassEnrollmentService $service,
        private readonly AuthDirectory $auth,
    ) {}

    /** Everything this account paid for, plus enrolments of learners it owns or signs in as (a child sees their own). */
    public function index(Request $request)
    {
        $learnerIds = $this->auth->learnerIdsVisibleTo($request->user()->id);
        $enrollments = $this->enrollments->visibleTo($request->user()->id, $learnerIds);
        $learners = $this->auth->learnersByIds($enrollments->pluck('learner_profile_id')->all());

        return response()->json([
            'data' => $enrollments->map(fn ($e) => new ClassEnrollmentResource($e, $learners[$e->learner_profile_id] ?? null)),
        ]);
    }

    public function show(Request $request, ClassEnrollment $enrollment)
    {
        $request->user()->can('view', $enrollment) || abort(404);

        return $this->present($enrollment);
    }

    /**
     * Call when the payer lands back from Paystack (the checkout's callback
     * URL). Confirms immediately if the payment went through, instead of
     * waiting for the webhook's event to reach Core. Safe to call repeatedly.
     */
    public function verifyPayment(Request $request, ClassEnrollment $enrollment)
    {
        $request->user()->can('pay', $enrollment) || abort(404);

        return $this->present($this->service->verifyPayment($enrollment));
    }

    private function present(ClassEnrollment $enrollment): ClassEnrollmentResource
    {
        $learners = $this->auth->learnersByIds([$enrollment->learner_profile_id]);

        return new ClassEnrollmentResource($enrollment->load('tewtoraClass.sessions'), $learners[$enrollment->learner_profile_id] ?? null);
    }
}
