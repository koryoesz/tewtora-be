<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Curriculum;
use Illuminate\Database\Eloquent\Collection;

interface CurriculumRepositoryInterface
{
    /** @return Collection<int, Curriculum> */
    public function active(): Collection;
}
