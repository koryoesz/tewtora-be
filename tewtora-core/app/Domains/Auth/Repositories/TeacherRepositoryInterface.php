<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

interface TeacherRepositoryInterface
{
    public function find(int $id): ?Teacher;

    public function findByPublicId(string $publicId): ?Teacher;

    public function pendingVerification(): Collection;

    /**
     * A just-signed-up teacher: verification_status pending, and one
     * pending row per verification check kind — without those rows
     * VerificationService::decide()'s "every check approved" test would be
     * vacuously true.
     */
    public function createApplicant(Account $account, string $fullName): Teacher;

    /**
     * Verified teachers matching every given filter — docs/needed-
     * endpoints-browse-matching.md §1's "minimum useful version" of
     * /matches: an unranked, filtered list of real teachers, not the
     * ranking engine §2 asks for later. Unverified teachers are excluded
     * outright, not just deprioritized — matches this codebase's existing
     * safeguarding-first posture (e.g. new_matches_suspended_at).
     *
     * @param  array{subject?: ?string, curriculum?: ?string, level?: ?string, format?: ?string}  $filters
     */
    public function search(array $filters): Collection;

    /**
     * @param  array{columns?: array, subjects?: ?array, curricula?: ?array, availability?: ?array}  $data
     */
    public function updateProfile(Teacher $teacher, array $data): Teacher;
}
