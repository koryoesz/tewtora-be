<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Core\Http\Requests\CancelTewtoraClassRequest;
use App\Domains\Core\Http\Requests\StoreTewtoraClassRequest;
use App\Domains\Core\Http\Requests\UpdateClassSessionRequest;
use App\Domains\Core\Http\Requests\UpdateTewtoraClassRequest;
use App\Domains\Core\Http\Requests\UploadClassImageRequest;
use App\Domains\Core\Http\Resources\TewtoraClassViews;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use App\Domains\Core\Services\TewtoraClassService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /internal/classes — admin-only (route group's `admin` middleware). */
class AdminTewtoraClassController
{
    public function __construct(
        private readonly TewtoraClassService $service,
        private readonly TewtoraClassRepositoryInterface $classes,
        private readonly TewtoraClassViews $views,
        private readonly AuthDirectory $auth,
    ) {}

    public function index(Request $request)
    {
        $request->validate(['status' => ['sometimes', Rule::in(['draft', 'published', 'cancelled', 'completed'])]]);

        return response()->json(['data' => $this->views->many($this->classes->forAdmin($request->query('status')), $request->user(), admin: true)]);
    }

    public function store(StoreTewtoraClassRequest $request)
    {
        $class = $this->service->create(
            $request->user(),
            $this->columns($request->validated()) + ['price_minor' => 0, 'currency_code' => 'NGN', 'format' => 'small_group'],
            $request->classDays() ?? [],
            $request->file('image'),
            (bool) $request->validated('publish', false),
        );

        return $this->views->one($class, $request->user(), admin: true)->response()->setStatusCode(201);
    }

    public function show(Request $request, TewtoraClass $class)
    {
        return $this->views->one($class, $request->user(), admin: true);
    }

    public function update(UpdateTewtoraClassRequest $request, TewtoraClass $class)
    {
        $class = $this->service->update($request->user(), $class, $this->columns($request->validated()), $request->classDays());

        return $this->views->one($class, $request->user(), admin: true);
    }

    /** Post or change one day's meeting link, or move that day. */
    public function updateSession(UpdateClassSessionRequest $request, TewtoraClass $class, int $day)
    {
        $class = $this->service->updateSession($request->user(), $class, $day, $request->validated());

        return $this->views->one($class, $request->user(), admin: true);
    }

    public function publish(Request $request, TewtoraClass $class)
    {
        return $this->views->one($this->service->publish($request->user(), $class), $request->user(), admin: true);
    }

    public function cancel(CancelTewtoraClassRequest $request, TewtoraClass $class)
    {
        return $this->views->one($this->service->cancel($request->user(), $class, $request->validated('reason')), $request->user(), admin: true);
    }

    public function uploadImage(UploadClassImageRequest $request, TewtoraClass $class)
    {
        return $this->views->one($this->service->replaceImage($class, $request->file('image')), $request->user(), admin: true);
    }

    public function deleteImage(Request $request, TewtoraClass $class)
    {
        return $this->views->one($this->service->removeImage($class), $request->user(), admin: true);
    }

    public function roster(TewtoraClass $class)
    {
        return response()->json(['data' => $this->views->roster($class)]);
    }

    /**
     * Request fields -> table columns. Resolves the cross-domain references
     * (subject and curriculum codes, teacher public_id) through Auth's
     * directory; anything not sent stays out, so PATCH only touches what it was given.
     */
    private function columns(array $validated): array
    {
        $columns = array_intersect_key($validated, array_flip([
            'title', 'description', 'level', 'format', 'art', 'price_minor', 'currency_code', 'capacity', 'meeting_link',
        ]));

        if (array_key_exists('subject', $validated)) {
            $columns['subject_id'] = $this->resolve('subject', $validated['subject'], fn ($code) => $this->auth->subject($code)?->id, 'Unknown or inactive subject.');
        }

        if (array_key_exists('curriculum', $validated)) {
            $columns['curriculum_id'] = $this->resolve('curriculum', $validated['curriculum'], fn ($code) => $this->auth->curriculum($code)?->id, 'Unknown or inactive curriculum.');
        }

        if (array_key_exists('teacher_id', $validated)) {
            $teacher = $validated['teacher_id'] !== null ? $this->auth->approvedTeacher($validated['teacher_id']) : null;

            if ($validated['teacher_id'] !== null && $teacher === null) {
                throw ValidationException::withMessages(['teacher_id' => 'No approved teacher with this id.']);
            }

            $columns['teacher_id'] = $teacher?->id;
            $columns['teacher_account_id'] = $teacher?->accountId;
        }

        return $columns;
    }

    private function resolve(string $field, ?string $code, callable $lookup, string $message): ?int
    {
        if ($code === null) {
            return null;
        }

        return $lookup($code) ?? throw ValidationException::withMessages([$field => $message]);
    }
}
