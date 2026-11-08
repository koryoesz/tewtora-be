<?php

namespace App\Domains\Auth\Contracts;

/**
 * Auth's service interface for other domains (CLAUDE.md: cross-domain data
 * goes through the owning domain's service interface, never its models).
 * Treat every call as if it were a network hop to a separate Auth service:
 * read-only, returns plain value objects, never Eloquent models.
 *
 * Synchronous on purpose (microservices-architecture.md §3: "the user is
 * waiting") — e.g. a parent enrolling a child they added a minute ago must
 * not wait for an event to reach a read model first.
 */
interface AuthDirectory
{
    public function learner(string $publicId): ?LearnerRef;

    /** @param  list<int>  $ids  @return array<int, LearnerRef> keyed by learner id */
    public function learnersByIds(array $ids): array;

    /**
     * Ids of learner profiles this account owns or signs in as (a child's
     * linked login).
     *
     * @return list<int>
     */
    public function learnerIdsVisibleTo(int $accountId): array;

    /** For notifying an account by email — null if it has none (a child login) or doesn't exist. */
    public function accountEmail(int $accountId): ?string;

    /** Approved teachers only — an unverified teacher can't be put in front of learners. */
    public function approvedTeacher(string $publicId): ?TeacherRef;

    /** @param  list<int>  $ids  @return array<int, TeacherRef> keyed by teacher id */
    public function teachersByIds(array $ids): array;

    /** Active subjects only. */
    public function subject(string $code): ?SubjectRef;

    /** @param  list<int>  $ids  @return array<int, SubjectRef> keyed by subject id */
    public function subjectsByIds(array $ids): array;

    /** Active curricula only. */
    public function curriculum(string $code): ?CurriculumRef;

    /** @param  list<int>  $ids  @return array<int, CurriculumRef> keyed by curriculum id */
    public function curriculaByIds(array $ids): array;
}
