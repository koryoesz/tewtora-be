<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Session;
use Illuminate\Database\Eloquent\Collection;

/** backend-engineering-standards.md §2's own worked example. */
interface SessionRepositoryInterface
{
    public function find(int $id): ?Session;

    public function findByPublicId(string $publicId): ?Session;

    public function upcomingFor(int $learnerProfileId): Collection;

    public function create(array $data): Session;

    public function transitionStatus(Session $session, string $status): Session;
}
