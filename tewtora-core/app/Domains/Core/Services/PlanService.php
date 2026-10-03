<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\InvalidPlanTransitionException;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Repositories\PlanRepositoryInterface;

class PlanService
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
    ) {}

    /** docs/api-contract.md §4: caller shows the consequence (sessions remaining) before confirming. */
    public function pause(Plan $plan): Plan
    {
        if ($plan->status !== 'active') {
            throw new InvalidPlanTransitionException($plan->id, 'pause', $plan->status);
        }

        return $this->plans->pause($plan);
    }

    /**
     * docs/api-contract.md §6: "the same endpoint whether the plan was
     * active (a straight renewal) or paused (a resume — carries over
     * sessionsRemaining automatically)." There is no separate resume
     * endpoint — §4 explicitly defers to this one.
     *
     * This is the plan-side half only (status/renews_at/
     * sessions_remaining). The payment side (§7's checkout) is not wired
     * up in this pass — see PlanController's docblock — so this call
     * assumes payment already succeeded, the same simplification the real
     * implementation will need to close before this ships.
     */
    public function rebook(Plan $plan, int $sessionCount): Plan
    {
        if ($plan->status === 'ended') {
            throw new InvalidPlanTransitionException($plan->id, 'rebook', $plan->status);
        }

        // A paused plan carries over its remaining sessions
        // (docs/api-contract.md §6's carriedOverSessions); an active plan
        // just adds the new block on top of what's left of the current one.
        return $this->plans->resume($plan, $plan->sessions_remaining + $sessionCount);
    }

    /** docs/api-contract.md §4: DELETE /plans/:id — ends, doesn't delete (see the migration note in database-design.md §3.4). */
    public function end(Plan $plan): Plan
    {
        if ($plan->status === 'ended') {
            throw new InvalidPlanTransitionException($plan->id, 'end', $plan->status);
        }

        return $this->plans->end($plan);
    }

    /** Unused sessions × rate — docs/api-contract.md §4's refundKobo. */
    public function refundOwedMinor(Plan $plan): int
    {
        return $plan->sessions_remaining * $plan->rate_minor;
    }

    /**
     * Auth's onboarding spec needs "refuse to remove a child with an
     * active plan" — a cross-domain check, so it goes through this public
     * service method (CLAUDE.md's hard rule) rather than Auth querying
     * core.plans directly.
     */
    public function hasActivePlan(int $learnerProfileId): bool
    {
        return $this->plans->forLearner($learnerProfileId)->contains('status', 'active');
    }
}
