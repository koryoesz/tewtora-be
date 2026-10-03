<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Curriculum;
use Illuminate\Database\Eloquent\Collection;

class EloquentCurriculumRepository implements CurriculumRepositoryInterface
{
    public function active(): Collection
    {
        return Curriculum::where('is_active', true)->orderBy('display_name')->get();
    }
}
