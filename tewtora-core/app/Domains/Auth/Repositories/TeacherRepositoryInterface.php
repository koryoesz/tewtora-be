<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

interface TeacherRepositoryInterface
{
    public function find(int $id): ?Teacher;

    public function findByPublicId(string $publicId): ?Teacher;

    public function pendingVerification(): Collection;
}
