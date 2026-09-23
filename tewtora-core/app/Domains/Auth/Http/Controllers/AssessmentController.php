<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\SaveAssessmentDraftRequest;
use App\Domains\Auth\Http\Requests\SubmitAssessmentRequest;
use App\Domains\Auth\Http\Resources\AssessmentResource;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\OutboxEvent;
use App\Domains\Auth\Repositories\AssessmentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssessmentController
{
    public function __construct(
        private readonly AssessmentRepositoryInterface $assessments,
    ) {}

    /** GET /learners/:id/assessment — current draft, if one exists. */
    public function show(Request $request, LearnerProfile $learner): AssessmentResource
    {
        $request->user()->can('manage', $learner) || abort(403);

        $assessment = $this->assessments->latestForLearner($learner->id);
        $assessment ?? abort(404);

        return new AssessmentResource($assessment);
    }

    /** PUT /learners/:id/assessment — autosaves on every step change. */
    public function saveDraft(SaveAssessmentDraftRequest $request, LearnerProfile $learner): AssessmentResource
    {
        $assessment = $this->assessments->firstOrNewDraftForLearner($learner->id);

        return new AssessmentResource($this->assessments->saveDraft($assessment, $request->validated()));
    }

    /**
     * POST /learners/:id/assessment/submit. Kicking off matching is
     * Recommendation's job, not something Auth computes synchronously by
     * reading Recommendation's tables (the same cross-domain hard rule
     * TeacherMatchPolicy/LearnerAccountLink already work around
     * elsewhere) — this writes AssessmentSubmitted to the outbox in the
     * same transaction and returns immediately.
     * `match_count` isn't in this response for that reason: the contract's
     * "returns { matchCount }" is only accurate once the frontend follows
     * up with `GET /matches?assessmentId=` against Recommendation's own
     * endpoint (not built this pass) after that event's been consumed.
     */
    public function submit(SubmitAssessmentRequest $request, LearnerProfile $learner)
    {
        return response()->json([
            'assessment' => new AssessmentResource(DB::transaction(function () use ($request, $learner) {
                $assessment = $this->assessments->firstOrNewDraftForLearner($learner->id);
                $assessment = $this->assessments->submit($assessment, $request->validated());

                OutboxEvent::create([
                    'aggregate_type' => 'assessment',
                    'aggregate_id' => $assessment->id,
                    'event_type' => 'AssessmentSubmitted',
                    'payload' => ['learner_profile_id' => $learner->id, 'assessment_id' => $assessment->id],
                ]);

                return $assessment;
            })),
        ]);
    }
}
