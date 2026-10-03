<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Resources\CurriculumResource;
use App\Domains\Auth\Repositories\CurriculumRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lets the frontend populate a curriculum picker (e.g. "Add a child") from
 * the real, currently-seeded set of codes instead of hardcoding a guessed
 * list against POST /learners' Rule::exists(Curriculum::class, 'code')
 * validation — see CurriculumSeeder's docblock for the gap this closes.
 */
class CurriculumController
{
    public function __construct(
        private readonly CurriculumRepositoryInterface $curricula,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return CurriculumResource::collection($this->curricula->active());
    }
}
