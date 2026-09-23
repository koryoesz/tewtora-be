<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\SaveFeedbackDraftRequest;
use App\Domains\Core\Http\Requests\SubmitFeedbackRequest;
use App\Domains\Core\Http\Resources\FeedbackResource;
use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\Session;
use App\Domains\Core\Repositories\FeedbackRepositoryInterface;
use App\Domains\Core\Services\FeedbackService;
use Illuminate\Http\Request;

class FeedbackController
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedback,
        private readonly FeedbackService $service,
    ) {}

    /** GET /sessions/:id/feedback */
    public function show(Request $request, Session $session): FeedbackResource
    {
        $feedback = $this->feedback->forSession($session->id);

        $feedback && $request->user()->can('view', $feedback) || abort($feedback ? 403 : 404);

        return new FeedbackResource($feedback);
    }

    /** PUT /sessions/:id/feedback/draft */
    public function saveDraft(SaveFeedbackDraftRequest $request, Session $session): FeedbackResource
    {
        $feedback = $this->draftFor($session);

        return new FeedbackResource($this->service->saveDraft($feedback, $request->validated()));
    }

    /** POST /sessions/:id/feedback — files it, releasing the session's held payment. */
    public function submit(SubmitFeedbackRequest $request, Session $session): FeedbackResource
    {
        $feedback = $this->draftFor($session);

        return new FeedbackResource($this->service->submit($feedback, $request->validated()));
    }

    private function draftFor(Session $session): Feedback
    {
        return $this->feedback->firstOrNewDraftForSession(
            $session->id,
            $session->teacher_id,
            $session->learner_profile_id,
        );
    }
}
