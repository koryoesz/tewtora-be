<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\CreateLearnerProfileRequest;
use App\Domains\Auth\Http\Requests\UpdateLearnerProfileRequest;
use App\Domains\Auth\Http\Resources\LearnerProfileResource;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Repositories\LearnerProfileRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
