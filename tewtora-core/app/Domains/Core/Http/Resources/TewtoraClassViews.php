<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Policies\TewtoraClassPolicy;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Builds class and class-day resources with everything they need loaded
 * once per request, not once per class: subjects, curricula and teachers
 * (through Auth's directory), seat counts, which of the viewer's learners
 * are enrolled, and the viewer's meeting-link access.
 */
class TewtoraClassViews
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
        private readonly TewtoraClassPolicy $policy,
    ) {}

    /** @return list<TewtoraClassResource> */
    public function many(Collection $classes, Account $viewer, bool $admin = false, bool $withSessions = false): array
    {
        $classes = $classes->values();
        $context = $this->context($classes, $viewer);

        return $classes->map(fn (TewtoraClass $class) => new TewtoraClassResource($class, $context($class), $withSessions, $admin))->all();
    }

    public function one(TewtoraClass $class, Account $viewer, bool $admin = false): TewtoraClassResource
    {
        $class->loadMissing('sessions');

        return $this->many(collect([$class]), $viewer, $admin, withSessions: true)[0];
    }

    /** @return list<TewtoraClassSessionResource> */
    public function sessions(TewtoraClass $class, Account $viewer): array
    {
        $class->loadMissing('sessions');
        $access = $this->policy->meetingLinkAccess($viewer, [$class])[$class->id] ?? null;

        return $class->sessions->map(fn ($s) => new TewtoraClassSessionResource($s, $class, $access))->all();
    }

    /**
     * The cross-class week schedule: each day carries a small class summary.
     *
     * @return list<TewtoraClassSessionResource>
     */
    public function schedule(Collection $sessions, Account $viewer): array
    {
        $classes = $sessions->pluck('tewtoraClass')->unique('id')->values();
        $access = $this->policy->meetingLinkAccess($viewer, $classes);
        $subjects = $this->auth->subjectsByIds($classes->pluck('subject_id')->filter()->all());
        $totals = $classes->mapWithKeys(fn ($c) => [$c->id => $c->sessions()->count()]);

        return $sessions->map(function ($session) use ($access, $subjects, $totals) {
            $class = $session->tewtoraClass;

            return new TewtoraClassSessionResource($session, $class, $access[$class->id] ?? null, [
                'id' => $class->public_id,
                'title' => $class->title,
                'subject' => ($subjects[$class->subject_id] ?? null)?->displayName,
                'art' => $class->art,
                'total_days' => $totals[$class->id],
            ]);
        })->all();
    }

    /** Who holds a seat (confirmed, or pending payment) — for admins and the class teacher. */
    public function roster(TewtoraClass $class): array
    {
        $enrollments = $this->enrollments->active($class)->load('tewtoraClass');
        $learners = $this->auth->learnersByIds($enrollments->pluck('learner_profile_id')->all());

        return $enrollments->map(fn ($e) => new ClassEnrollmentResource($e, $learners[$e->learner_profile_id] ?? null))->all();
    }

    /** @return callable(TewtoraClass): array */
    private function context(Collection $classes, Account $viewer): callable
    {
        $ids = $classes->pluck('id')->all();
        $seats = $this->enrollments->seatsTakenFor($ids);
        $subjects = $this->auth->subjectsByIds($classes->pluck('subject_id')->filter()->all());
        $curricula = $this->auth->curriculaByIds($classes->pluck('curriculum_id')->filter()->all());
        $teachers = $this->auth->teachersByIds($classes->pluck('teacher_id')->filter()->all());
        $access = $this->policy->meetingLinkAccess($viewer, $classes);

        $myLearnerIds = $this->auth->learnerIdsVisibleTo($viewer->id);
        $enrolledByClass = $this->enrollments->confirmedLearnersByClass($ids, $myLearnerIds);
        $publicIds = collect($this->auth->learnersByIds(array_merge([], ...array_values($enrolledByClass))))
            ->map(fn ($ref) => $ref->publicId);

        return fn (TewtoraClass $class) => [
            'subject' => $subjects[$class->subject_id] ?? null,
            'curriculum' => $curricula[$class->curriculum_id] ?? null,
            'teacher' => $teachers[$class->teacher_id] ?? null,
            'seats_taken' => $seats[$class->id] ?? 0,
            'enrolled_learner_ids' => collect($enrolledByClass[$class->id] ?? [])->map(fn ($id) => $publicIds[$id] ?? null)->filter()->values()->all(),
            'link_access' => $access[$class->id] ?? null,
        ];
    }
}
