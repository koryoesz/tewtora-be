<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\LoginRequest;
use App\Domains\Auth\Http\Requests\SwitchProfileRequest;
use App\Domains\Auth\Repositories\LearnerProfileRepositoryInterface;
use App\Domains\Auth\Services\AuthSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * docs/api-contract.md §0. role is always resolved server-side from the
 * session/token (CLAUDE.md's enforcement note) — never trusted from a
 * request body anywhere else in this codebase.
 */
class AuthController
{
    private const ACTING_AS_COOKIE = 'acting_as_learner_id';

    public function __construct(
        private readonly AuthSessionService $service,
        private readonly LearnerProfileRepositoryInterface $learnerProfiles,
    ) {}

    public function login(LoginRequest $request)
    {
        $token = $this->service->login($request->validated());

        return response()->json(['token' => $token->plainTextToken]);
    }

    public function logout(Request $request)
    {
        $this->service->logout($request->user());

        return response()->json(status: 204);
    }

    public function session(Request $request)
    {
        $account = $request->user();
        $actingAsPublicId = $request->cookie(self::ACTING_AS_COOKIE);

        return response()->json([
            'id' => $account->public_id,
            'role' => $account->account_type,
            // A child account has no email (signs in via username + PIN).
            'name' => $account->email ?? $account->username,
            'acting_as_learner_id' => $actingAsPublicId,
            // The account's own public_id is a different UUID from the
            // linked auth.teachers row's — GET /teachers/{id} and
            // GET /teachers/{id}/match-requests need the latter, and had
            // no way to resolve it from the session at all. Same-domain
            // lookup (Account::teacher(), both auth.* tables) — not a
            // cross-domain reach. Only present for a teacher session, not
            // a null field on everyone else's.
            ...($account->account_type === 'teacher' ? ['teacher_id' => $account->teacher?->public_id] : []),
        ]);
    }

    /**
     * docs/api-contract.md §0: "Must be a server-readable session/cookie
     * value, not client state" — a plain (not encrypted) cookie, since the
     * value (a public_id) is already safe to expose and the Next.js
     * frontend's Server Components need to read it directly.
     */
    public function switchProfile(SwitchProfileRequest $request)
    {
        // findByPublicId() bypasses LearnerProfile's ownedByAccount global
        // scope — a direct ::where() lookup would make a stranger's request
        // silently find nothing and 404 before the explicit policy check
        // below ever runs, when docs/frontend-integration-guide.md promises
        // a 403 here.
        $learner = $this->learnerProfiles->findByPublicId($request->validated('learner_id'));

        $learner ?: abort(404);
        $request->user()->can('view', $learner) || abort(403);

        Cookie::queue(Cookie::make(
            self::ACTING_AS_COOKIE,
            $learner->public_id,
            60 * 24,
            httpOnly: false,
            raw: true,
        ));

        return response()->json(['acting_as_learner_id' => $learner->public_id]);
    }
}
