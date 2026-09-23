<?php

namespace App\Domains\Recommendation\Http\Controllers;

use App\Domains\Recommendation\Http\Requests\DeclineMatchRequest;
use App\Domains\Recommendation\Http\Resources\TeacherMatchResource;
use App\Domains\Recommendation\Models\TeacherMatch;
use App\Domains\Recommendation\Models\TeacherProfileView;
use App\Domains\Recommendation\Repositories\TeacherMatchRepositoryInterface;
use App\Domains\Recommendation\Services\TeacherMatchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherMatchController
{
    public function __construct(
        private readonly TeacherMatchRepositoryInterface $matches,
        private readonly TeacherMatchService $service,
    ) {}

    /** docs/api-contract.md §9: GET /teachers/:id/match-requests — teacher (self) only. */
    public function index(Request $request, string $teacherPublicId): AnonymousResourceCollection
    {
        $teacher = TeacherProfileView::where('public_id', $teacherPublicId)
            ->where('account_id', $request->user()->id)
            ->firstOrFail();

        return TeacherMatchResource::collection($this->matches->pendingForTeacher($teacher->id));
    }

    public function accept(Request $request, TeacherMatch $match): TeacherMatchResource
    {
        $request->user()->can('respond', $match) || abort(403);

        return new TeacherMatchResource($this->service->accept($match));
    }

    public function decline(DeclineMatchRequest $request, TeacherMatch $match): TeacherMatchResource
    {
        return new TeacherMatchResource($this->service->decline($match, $request->validated('reason')));
    }
}
