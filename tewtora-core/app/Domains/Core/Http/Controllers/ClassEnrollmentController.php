<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Core\Http\Requests\VerifyClassPaymentRequest;
use App\Domains\Core\Http\Resources\ClassEnrollmentResource;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Services\ClassEnrollmentService;
use App\Domains\Core\Services\Payments\ClassPaymentVerification;
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

    /**
     * GET /learners/{id}/class-enrollments — one child's class seats, for the
     * plans page next to GET /learners/{id}/plans. Only seats that count
     * (confirmed, or pending with a live hold). The owner or the child's own
     * login only, like the plans list.
     */
    public function forLearner(Request $request, string $learnerPublicId)
    {
        $learner = $this->auth->learner($learnerPublicId) ?? abort(404);
        in_array($learner->id, $this->auth->learnerIdsVisibleTo($request->user()->id), true) || abort(403);

        return response()->json([
            'data' => $this->enrollments->seatsHeldBy($learner->id)->map(fn ($e) => new ClassEnrollmentResource($e, $learner)),
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
     * `verification.enrolled` is the answer to "is this learner in the class".
     */
    public function verifyPayment(Request $request, ClassEnrollment $enrollment)
    {
        $request->user()->can('pay', $enrollment) || abort(404);

        return $this->presentVerification($this->service->verifyPayment($enrollment));
    }

    /**
     * The same, by the `?reference=` Paystack appends to the return URL —
     * for a custom redirect_url that doesn't carry the enrolment id. 404 for
     * an unknown reference and for someone else's (no probing references).
     */
    public function verifyPaymentByReference(VerifyClassPaymentRequest $request)
    {
        $enrollment = $this->service->findByPaymentReference($request->validated('reference'));

        if ($enrollment === null || ! $request->user()->can('pay', $enrollment)) {
            abort(404);
        }

        return $this->presentVerification($this->service->verifyPayment($enrollment));
    }

    private function presentVerification(ClassPaymentVerification $verification)
    {
        return $this->present($verification->enrollment)->additional(['verification' => $verification->toArray()]);
    }

    private function present(ClassEnrollment $enrollment): ClassEnrollmentResource
    {
        $learners = $this->auth->learnersByIds([$enrollment->learner_profile_id]);

        return new ClassEnrollmentResource($enrollment->load('tewtoraClass.sessions'), $learners[$enrollment->learner_profile_id] ?? null);
    }
}
