<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Exceptions\ChildUsernameRequiredException;
use App\Domains\Auth\Exceptions\LearnerHasActivePlanException;
use App\Domains\Auth\Http\Requests\CreateLearnerProfileRequest;
use App\Domains\Auth\Http\Requests\SetLearnerPinRequest;
use App\Domains\Auth\Http\Requests\UpdateLearnerProfileRequest;
use App\Domains\Auth\Http\Resources\LearnerProfileResource;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Repositories\LearnerProfileRepositoryInterface;
use App\Domains\Core\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LearnerProfileController
{
    public function __construct(
        private readonly LearnerProfileRepositoryInterface $learnerProfiles,
        private readonly PlanService $plans,
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

        $childAccount = $request->filled('username')
            ? $this->createChildAccount($request->validated('username'))
            : null;

        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh.
        $profile = $this->learnerProfiles->create([
            'owner_account_id' => $request->user()->id,
            'linked_login_account_id' => $childAccount?->id,
            'profile_type' => 'child',
            'full_name' => $request->validated('name'),
            'grade_level' => $request->validated('grade_level'),
            'curriculum_id' => $curriculum->id,
            'pin_hash' => $request->has('pin') ? Hash::make($request->validated('pin')) : null,
        ])->refresh();

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
     * and use the new PIN next time." Also handles first-time setup: a
     * profile created without a username/pin (via POST /learners) has no
     * child login yet — this is where one gets created, given a username.
     */
    public function setPin(SetLearnerPinRequest $request, LearnerProfile $learner): LearnerProfileResource
    {
        if ($learner->linked_login_account_id === null) {
            $request->filled('username') || throw new ChildUsernameRequiredException;

            $childAccount = $this->createChildAccount($request->validated('username'));
            $learner = $this->learnerProfiles->update($learner, ['linked_login_account_id' => $childAccount->id]);
        } elseif ($request->filled('username')) {
            Account::where('id', $learner->linked_login_account_id)->update(['username' => $request->validated('username')]);
        }

        $learner = $this->learnerProfiles->update($learner, [
            'pin_hash' => Hash::make($request->validated('pin')),
        ]);

        $learner->linkedLoginAccount?->tokens()->delete();

        return new LearnerProfileResource($learner->load('curriculum'));
    }

    public function archive(Request $request, LearnerProfile $learner): LearnerProfileResource
    {
        $request->user()->can('manage', $learner) || abort(403);

        // Onboarding spec: "refuse if the child has an active plan." Goes
        // through Core's PlanService (CLAUDE.md's hard rule), not a direct
        // query against core.plans.
        if ($this->plans->hasActivePlan($learner->id)) {
            throw new LearnerHasActivePlanException;
        }

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

    /**
     * Disables the child's own username+PIN login (AuthSessionService::
     * loginChild) while leaving the profile itself, and archive/restore,
     * completely untouched — deliberately a separate toggle from archive,
     * never folded into update()'s `sometimes` PATCH semantics, same
     * reasoning as setPin() having its own action.
     */
    public function pauseSignIn(Request $request, LearnerProfile $learner): LearnerProfileResource
    {
        $request->user()->can('manage', $learner) || abort(403);

        $learner = $this->learnerProfiles->pauseSignIn($learner);

        return new LearnerProfileResource($learner->load('curriculum'));
    }

    public function resumeSignIn(Request $request, LearnerProfile $learner): LearnerProfileResource
    {
        $request->user()->can('manage', $learner) || abort(403);

        $learner = $this->learnerProfiles->resumeSignIn($learner);

        return new LearnerProfileResource($learner->load('curriculum'));
    }

    /**
     * A child never logs in with email/password — password_hash is set to
     * an unusable random value purely to satisfy the NOT NULL column; the
     * account authenticates via username + the linked learner profile's
     * pin_hash instead (AuthSessionService::loginChild).
     */
    private function createChildAccount(string $username): Account
    {
        return Account::create([
            'username' => $username,
            'password_hash' => Hash::make(Str::random(40)),
            'account_type' => 'child',
        ])->refresh();
    }
}
