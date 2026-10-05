<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

interface TeacherRepositoryInterface
{
    public function find(int $id): ?Teacher;

    public function findByPublicId(string $publicId): ?Teacher;

    public function pendingVerification(): Collection;

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
