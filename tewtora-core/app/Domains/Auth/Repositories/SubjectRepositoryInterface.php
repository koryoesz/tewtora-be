<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Subject;
use Illuminate\Database\Eloquent\Collection;

interface SubjectRepositoryInterface
{
    /** @return Collection<int, Subject> */
    public function active(): Collection;
}
