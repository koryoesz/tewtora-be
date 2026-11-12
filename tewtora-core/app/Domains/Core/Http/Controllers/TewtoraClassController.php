<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Core\Http\Requests\BrowseClassesRequest;
use App\Domains\Core\Http\Requests\ClassScheduleRequest;
use App\Domains\Core\Http\Requests\EnrollInClassRequest;
use App\Domains\Core\Http\Requests\RateClassRequest;
use App\Domains\Core\Http\Resources\ClassEnrollmentResource;
use App\Domains\Core\Http\Resources\TewtoraClassViews;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use App\Domains\Core\Services\ClassEnrollmentService;
use App\Domains\Core\Services\ClassRatingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The family-facing classes API (tewtora-fe docs/needed-endpoints-tewtora-classes.md):
 * catalogue, class page, class days, week schedule, enrol and pay, rate;
 * plus the class teacher's roster. Any signed-in account can browse
 * (parents and children both see the full list); only the payer can enrol.
 */
class TewtoraClassController
{
    public function __construct(
        private readonly TewtoraClassRepositoryInterface $classes,
        private readonly ClassEnrollmentService $enrollments,
        private readonly ClassRatingService $ratings,
        private readonly TewtoraClassViews $views,
        private readonly AuthDirectory $auth,
    ) {}

    /** §1 — the catalogue, with the All/Active/Upcoming/Completed tabs as `status`. */
    public function index(BrowseClassesRequest $request)
    {
        $filters = ['phase' => $request->validated('status'), 'level' => $request->validated('level')];

        foreach (['subject' => 'subject_id', 'curriculum' => 'curriculum_id'] as $param => $column) {
            if ($request->filled($param)) {
                $filters[$column] = $param === 'subject'
                    ? $this->auth->subject($request->validated($param))?->id
                    : $this->auth->curriculum($request->validated($param))?->id;

                if ($filters[$column] === null) {
                    return response()->json(['data' => []]); // unknown code: nothing matches
                }
            }
        }

        return response()->json(['data' => $this->views->many($this->classes->catalogue($filters), $request->user())]);
    }

    /** §2 — one class, with its class days. Drafts and cancelled classes 404 unless you were in them. */
    public function show(Request $request, TewtoraClass $class)
    {
        $request->user()->can('view', $class) || abort(404);

        return $this->views->one($class, $request->user());
    }

    /** §2 — the dated class days, each with `meeting_url` when the viewer may see it. */
    public function sessions(Request $request, TewtoraClass $class)
    {
        $request->user()->can('view', $class) || abort(404);

        return response()->json(['data' => $this->views->sessions($class, $request->user())]);
    }

    /** §2 — class days across every class in a date range (the week strip and its schedule). */
    public function schedule(ClassScheduleRequest $request)
    {
        $tz = config('classes.display_timezone');
        $from = CarbonImmutable::parse($request->validated('from'), $tz)->startOfDay();
        $to = CarbonImmutable::parse($request->validated('to'), $tz)->addDay()->startOfDay();

        return response()->json(['data' => $this->views->schedule($this->classes->sessionsBetween($from->utc(), $to->utc()), $request->user())]);
    }

    /**
     * §3. 201 with the enrolment. Paid class: `pending_payment` with a
     * `checkout_url` to send the payer to. Free class: already `confirmed`.
     * Calling again for the same learner while the hold is live returns the
     * same enrolment and checkout (200), never a second seat.
     */
    public function enroll(EnrollInClassRequest $request, TewtoraClass $class)
    {
        $request->user()->can('view', $class) || abort(404);

        $learner = $this->auth->learner($request->validated('learner_id'));

        if ($learner === null || ! $request->user()->can('enroll', [$class, $learner])) {
            abort(403, 'You can only enrol your own learner profile, and only a parent or independent student can pay.');
        }

        $enrollment = $this->enrollments->enroll($class, $learner, $request->user(), $request->validated('redirect_url'));

        return (new ClassEnrollmentResource($enrollment->load('tewtoraClass'), $learner))
            ->response()
            ->setStatusCode($enrollment->wasRecentlyCreated ? 201 : 200);
    }

    /** Families with a confirmed seat rate the class 1-5 once a class day has finished; rating again replaces it. */
    public function rate(RateClassRequest $request, TewtoraClass $class)
    {
        $request->user()->can('rate', $class) || abort(403, 'Only a family enrolled in this class can rate it.');

        $class = $this->ratings->rate($request->user(), $class, (int) $request->validated('rating'), $request->validated('comment'));

        return response()->json(['data' => ['rating_avg' => $class->rating_avg, 'rating_count' => $class->rating_count]]);
    }

    /** The class teacher's (or an admin's) view of who's in the class. */
    public function roster(Request $request, TewtoraClass $class)
    {
        $request->user()->can('viewRoster', $class) || abort(403);

        return response()->json(['data' => $this->views->roster($class)]);
    }
}
