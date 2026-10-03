<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Resources\SubjectResource;
use App\Domains\Auth\Repositories\SubjectRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Mirrors CurriculumController — see SubjectSeeder's docblock for the gap this closes. */
class SubjectController
{
    public function __construct(
        private readonly SubjectRepositoryInterface $subjects,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return SubjectResource::collection($this->subjects->active());
    }
}
