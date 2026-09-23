<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

class EloquentTeacherRepository implements TeacherRepositoryInterface
{
    public function find(int $id): ?Teacher
    {
        return Teacher::find($id);
    }

    public function findByPublicId(string $publicId): ?Teacher
    {
        return Teacher::where('public_id', $publicId)->first();
    }

    public function pendingVerification(): Collection
    {
        return Teacher::where('verification_status', 'pending')->get();
    }
}
