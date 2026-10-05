<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\BrowseTeachersRequest;
use App\Domains\Auth\Http\Requests\UpdateTeacherProfileRequest;
use App\Domains\Auth\Http\Resources\TeacherResource;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Repositories\TeacherRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherController
{
    public function __construct(
        private readonly TeacherRepositoryInterface $teachers,
    ) {}

    /** GET /teachers — see BrowseTeachersRequest's docblock. */
    public function index(BrowseTeachersRequest $request): AnonymousResourceCollection
    {
        return TeacherResource::collection($this->teachers->search($request->validated()));
    }

    public function show(Request $request, Teacher $teacher): TeacherResource
    {
        $request->user()->can('view', $teacher) || abort(403);

        return new TeacherResource($teacher->load(['verificationChecks', 'subjects', 'curricula', 'availability']));
    }

    /** PATCH /teachers/:id — see UpdateTeacherProfileRequest's docblock. */
    public function update(UpdateTeacherProfileRequest $request, Teacher $teacher): TeacherResource
    {
        $columns = [];
        if ($request->has('name')) {
            $columns['full_name'] = $request->validated('name');
        }
        if ($request->has('years_teaching')) {
            $columns['years_experience'] = $request->validated('years_teaching');
        }
        if ($request->has('about')) {
            $columns['bio'] = $request->validated('about');
        }
        if ($request->has('format')) {
            $columns['preferred_format'] = $request->validated('format');
        }
        if ($request->has('price_per_session_minor')) {
            $columns['rate_minor'] = $request->validated('price_per_session_minor');
        }
        if ($request->has('levels')) {
            $columns['levels'] = $request->validated('levels');
        }

        $teacher = $this->teachers->updateProfile($teacher, [
            'columns' => $columns,
            'subjects' => $request->has('subjects') ? $request->validated('subjects') : null,
            'curricula' => $request->has('curricula') ? $request->validated('curricula') : null,
            'availability' => $request->has('availability') ? $request->validated('availability') : null,
        ]);

        return new TeacherResource($teacher->load(['verificationChecks', 'subjects', 'curricula', 'availability']));
    }
}
