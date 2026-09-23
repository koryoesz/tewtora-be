<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Resources\TeacherResource;
use App\Domains\Auth\Models\Teacher;
use Illuminate\Http\Request;

class TeacherController
{
    public function show(Request $request, Teacher $teacher): TeacherResource
    {
        $request->user()->can('view', $teacher) || abort(403);

        return new TeacherResource($teacher->load(['verificationChecks', 'subjects', 'curricula']));
    }
}
