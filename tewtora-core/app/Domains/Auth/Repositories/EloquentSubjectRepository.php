<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Subject;
use Illuminate\Database\Eloquent\Collection;

class EloquentSubjectRepository implements SubjectRepositoryInterface
{
    public function active(): Collection
    {
        return Subject::where('is_active', true)->orderBy('display_name')->get();
    }
}
