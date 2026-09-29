<?php

namespace Tests\Concerns;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\Subject;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\TeacherAccountLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Minimal fixture builders for the Auth graph every other domain's tests
 * hang off (an account owns a learner profile; a teacher belongs to an
 * account). Plain Model::create() calls rather than factories — these
 * models don't use HasFactory, and the fixture needs are simple enough
 * not to justify adding it.
 */
trait SeedsAuthGraph
{
    protected function makeAccount(array $overrides = []): Account
    {
        return Account::create(array_merge([
            'email' => 'user-'.uniqid().'@example.test',
            'password_hash' => password_hash('password', PASSWORD_ARGON2ID),
            'account_type' => 'parent',
        ], $overrides));
    }

    protected function makeCurriculum(array $overrides = []): Curriculum
    {
        return Curriculum::create(array_merge([
            'code' => 'curriculum-'.uniqid(),
            'display_name' => 'Test Curriculum',
        ], $overrides));
    }

    protected function makeSubject(array $overrides = []): Subject
    {
        return Subject::create(array_merge([
            'code' => 'subject-'.uniqid(),
            'display_name' => 'Test Subject',
        ], $overrides));
    }

    protected function makeLearnerProfile(array $overrides = []): LearnerProfile
    {
        $owner = $overrides['owner_account_id'] ?? $this->makeAccount()->id;
        $curriculum = $overrides['curriculum_id'] ?? $this->makeCurriculum()->id;

        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh, which every
        // route in these tests needs (they're keyed by public_id).
        return LearnerProfile::create(array_merge([
            'owner_account_id' => $owner,
            'profile_type' => 'own',
            'full_name' => 'Test Learner',
            'grade_level' => 'grade-6',
            'curriculum_id' => $curriculum,
        ], $overrides))->refresh();
    }

    protected function makeTeacher(array $overrides = []): Teacher
    {
        $account = $overrides['account_id'] ?? $this->makeAccount(['account_type' => 'teacher'])->id;

        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh, which every
        // route in these tests needs (they're keyed by public_id).
        return Teacher::create(array_merge([
            'account_id' => $account,
            'years_experience' => 3,
            'preferred_format' => 'both',
            'rate_minor' => 500000,
        ], $overrides))->refresh();
    }

    /**
     * Keeps core.learner_account_links / core.teacher_account_links in
     * sync with a fixture — in production these are kept up to date by
     * event listeners (not built this pass, see CoreServiceProvider's
     * docblock trail), so tests populate them directly instead.
     */
    protected function linkLearnerToCore(LearnerProfile $learner): LearnerAccountLink
    {
        return LearnerAccountLink::create([
            'learner_profile_id' => $learner->id,
            'public_id' => $learner->public_id,
            'owner_account_id' => $learner->owner_account_id,
            'linked_login_account_id' => $learner->linked_login_account_id,
        ]);
    }

    protected function linkTeacherToCore(Teacher $teacher): TeacherAccountLink
    {
        return TeacherAccountLink::create([
            'teacher_id' => $teacher->id,
            'public_id' => $teacher->public_id,
            'account_id' => $teacher->account_id,
        ]);
    }

    /**
     * A Sanctum token for HTTP feature tests — actingAs() alone doesn't
     * populate currentAccessToken(). Default is NOT Sanctum's bare '*'
     * wildcard: PersonalAccessToken::can() treats '*' as satisfying every
     * ability check, including the literal 'act-as-readonly' string
     * BlockActAsMutations looks for — so a '*' token gets treated as a
     * read-only act-as session and 403s on every mutation. Production
     * never issues a bare '*' token (AuthSessionService grants named
     * per-role abilities; only AccountSearchService's real act-as flow
     * grants 'act-as-readonly' specifically), so this is a test-fixture
     * bug, not something the middleware needs to account for.
     *
     * Auth::forgetGuards() is required here, not cosmetic: the 'sanctum'
     * guard is a RequestGuard that caches its resolved user for the life
     * of the guard instance, which — inside a single test method — outlives
     * any one simulated HTTP call. Without forgetting it, a second tokenFor()
     * call for a different actor still authenticates every later request in
     * the test as whichever account resolved first, no matter what Bearer
     * token is actually sent (silent false-positive authorization in
     * multi-actor tests, e.g. a "stranger" getting treated as the owner).
     */
    protected function tokenFor(Account $account, array $abilities = ['test:full-access']): string
    {
        Auth::forgetGuards();

        return $account->createToken('test-'.Str::random(8), $abilities)->plainTextToken;
    }
}
