<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\RebookPlanRequest;
use App\Domains\Core\Http\Resources\PlanGoalResource;
use App\Domains\Core\Http\Resources\PlanHistoryEntryResource;
use App\Domains\Core\Http\Resources\PlanNextSessionResource;
use App\Domains\Core\Http\Resources\PlanResource;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Repositories\PlanRepositoryInterface;
use App\Domains\Core\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Rebook (§6) is a payment per docs/api-contract.md ("this endpoint should
 * return a checkout/payment intent, not silently mark the plan paid") —
 * not wired to Payment's checkout in this pass (see
 * docs/api-gap-analysis.md §7 for the cross-service pricing gap that
 * blocks it), so this transitions the plan as if payment already
 * succeeded. `note_to_teacher` is accepted and validated but not
 * delivered anywhere — no messaging system exists yet.
 */
class PlanController
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly PlanService $service,
    ) {}

    /**
     * GET /learners/:id/plans — :id is a learner_profiles.public_id.
     * Resolved via Core's own learner_account_links read model (which
     * mirrors public_id, see 2024_02_01_000085) rather than Auth's
     * LearnerProfile model directly — the usual cross-domain rule.
     */
    public function index(Request $request, string $learnerPublicId): AnonymousResourceCollection
    {
        $link = LearnerAccountLink::where('public_id', $learnerPublicId)->firstOrFail();

        $isOwnerOrLinked = $link->owner_account_id === $request->user()->id
            || $link->linked_login_account_id === $request->user()->id;

        $isOwnerOrLinked || abort(403);

        return PlanResource::collection($this->plans->forLearner($link->learner_profile_id));
    }

    public function show(Request $request, Plan $plan): PlanResource
    {
        $request->user()->can('view', $plan) || abort(403);

        return new PlanResource($plan);
    }

    public function nextSessions(Request $request, Plan $plan): AnonymousResourceCollection
    {
        $request->user()->can('view', $plan) || abort(403);

        return PlanNextSessionResource::collection(
            $plan->sessions()->where('status', 'scheduled')->orderBy('scheduled_at')->get()
        );
    }

    public function goals(Request $request, Plan $plan): AnonymousResourceCollection
    {
        $request->user()->can('view', $plan) || abort(403);

        return PlanGoalResource::collection($plan->goals);
    }

    public function history(Request $request, Plan $plan): AnonymousResourceCollection
    {
        $request->user()->can('view', $plan) || abort(403);

        return PlanHistoryEntryResource::collection(
            $plan->sessions()->with('feedback')->orderByDesc('scheduled_at')->get()
        );
    }

    public function pause(Request $request, Plan $plan): PlanResource
    {
        $request->user()->can('manage', $plan) || abort(403);

        return new PlanResource($this->service->pause($plan));
    }

    public function rebook(RebookPlanRequest $request, Plan $plan): PlanResource
    {
        return new PlanResource($this->service->rebook($plan, $request->validated('session_count')));
    }

    public function destroy(Request $request, Plan $plan): array
    {
        $request->user()->can('manage', $plan) || abort(403);

        $refundMinor = $this->service->refundOwedMinor($plan);
        $this->service->end($plan);

        return ['refund_minor' => $refundMinor];
    }
}
