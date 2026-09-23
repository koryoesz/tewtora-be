<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\MoveRequest;
use Illuminate\Database\Eloquent\Collection;

interface MoveRequestRepositoryInterface
{
    public function find(int $id): ?MoveRequest;

    public function findByPublicId(string $publicId): ?MoveRequest;

    public function forTeacher(int $teacherId): Collection;

    public function forLearner(int $learnerProfileId): Collection;

    public function create(array $data): MoveRequest;
}
