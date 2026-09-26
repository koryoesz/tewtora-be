<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\CreateLearnerProfileRequest;
use App\Domains\Auth\Http\Requests\SetLearnerPinRequest;
use App\Domains\Auth\Http\Requests\UpdateLearnerProfileRequest;
use App\Domains\Auth\Http\Resources\LearnerProfileResource;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Repositories\LearnerProfileRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class LearnerProfileController
{
    public function __construct(
        private readonly LearnerProfileRepositoryInterface $learnerProfiles,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LearnerProfileResource::collection(
            $this->learnerProfiles->forAccount($request->user())->load('curriculum')
        );
    }

    public function show(Request $request, LearnerProfile $learner): LearnerProfileResource
    {
        $request->user()->can('view', $learner) || abort(403);

        return new LearnerProfileResource($learner->load('curriculum'));
    }

    public function store(CreateLearnerProfileRequest $request): JsonResponse
    {
        $curriculum = Curriculum::where('code', $request->validated('curriculum'))->firstOrFail();

        $profile = $this->learnerProfiles->create([
            'owner_account_id' => $request->user()->id,
            'profile_type' => 'child',
            'full_name' => $request->validated('name'),
            'grade_level' => $request->validated('grade_level'),
            'curriculum_id' => $curriculum->id,
            'pin_hash' => $request->has('pin') ? Hash::make($request->validated('pin')) : null,
        ]);

        return (new LearnerProfileResource($profile->load('curriculum')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateLearnerProfileRequest $request, LearnerProfile $learner): LearnerProfileResource
    {
        $data = [];

        if ($request->has('name')) {
            $data['full_name'] = $request->validated('name');
        }
        if ($request->has('grade_level')) {
            $data['grade_level'] = $request->validated('grade_level');
        }
        if ($request->has('curriculum')) {
            $data['curriculum_id'] = Curriculum::where('code', $request->validated('curriculum'))->firstOrFail()->id;
        }

        $profile = $this->learnerProfiles->update($learner, $data);

        return new LearnerProfileResource($profile->load('curriculum'));
    }

    /**
     * A dedicated action rather than folding into update() — its own auth
     * check, and it can't be silently skipped by PATCH's `sometimes` rules.
     * Setting/changing a PIN revokes the child's existing sessions: the
     * frontend's own copy already promises "they're signed out everywhere
     * and use the new PIN next time."
     */
    public function setPin(SetLearnerPinRequest $request, LearnerProfile $learner): LearnerProfileResource
    {
        $learner = $this->learnerProfiles->update($learner, [
            'pin_hash' => Hash::make($request->validated('pin')),
        ]);

        $learner->linkedLoginAccount?->tokens()->delete();

        return new LearnerProfileResource($learner->load('curriculum'));
    }

    public function archive(Request $request, LearnerProfile $learner): LearnerProfileResource
    {
        $request->user()->can('manage', $learner) || abort(403);

        $this->learnerProfiles->archive($learner);

        return new LearnerProfileResource($learner->fresh()->load('curriculum'));
    }

    /**
     * Route model binding excludes soft-deleted rows, so this takes the raw
     * public_id and resolves through the repository (which drops all
     * scopes, trashed included) instead of {learner:public_id} binding.
     */
    public function restore(Request $request, string $learnerPublicId): LearnerProfileResource
    {
        $learner = $this->learnerProfiles->findByPublicId($learnerPublicId) ?? abort(404);

        $request->user()->can('manage', $learner) || abort(403);

        $this->learnerProfiles->restore($learner);

        return new LearnerProfileResource($learner->fresh()->load('curriculum'));
    }
}
