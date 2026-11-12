<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Auth\Contracts\CurriculumRef;
use App\Domains\Auth\Contracts\SubjectRef;
use App\Domains\Auth\Contracts\TeacherRef;
use App\Domains\Core\Models\TewtoraClass;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The catalogue shape the frontend wires to (tewtora-fe
 * docs/needed-endpoints-tewtora-classes.md §1, lib/classes.ts TewtoraClass).
 * Built by TewtoraClassViews, which batch-loads every cross-domain or
 * per-viewer value for a page in a fixed number of queries.
 *
 * - status: the phase families see (upcoming|active|completed), from the backend.
 * - days / starts_at_time / ends_at_time / starts_on / ends_on: Lagos time
 *   (config classes.display_timezone), taken from the class days.
 * - seats_left: null = no cap.
 * - enrolled_learner_ids: which of the viewer's own learners hold a seat.
 * - sessions: only on the detail view.
 * The admin shape adds the lifecycle and housekeeping fields.
 *
 * @mixin TewtoraClass
 */
class TewtoraClassResource extends JsonResource
{
    /**
     * @param  array{subject: ?SubjectRef, curriculum: ?CurriculumRef, teacher: ?TeacherRef, seats_taken: int, enrolled_learner_ids: list<string>, link_access: ?string}  $context
     */
    public function __construct(
        TewtoraClass $class,
        private readonly array $context,
        private readonly bool $withSessions = false,
        private readonly bool $admin = false,
    ) {
        parent::__construct($class);
    }

    public function toArray(Request $request): array
    {
        $tz = config('classes.display_timezone');
        $sessions = $this->sessions;
        $first = $sessions->first();
        $now = now();
        $next = $sessions->first(fn ($s) => $s->ends_at->gt($now));

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'subject' => $this->context['subject']?->displayName,
            'subject_code' => $this->context['subject']?->code,
            'description' => $this->description,
            'art' => $this->art,
            'image_url' => $this->imageUrl(),
            'level' => $this->level,
            'curriculum' => $this->context['curriculum']?->code,
            'curriculum_name' => $this->context['curriculum']?->displayName,
            'format' => $this->format,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'teacher' => $this->context['teacher'] ? ['id' => $this->context['teacher']->publicId, 'name' => $this->context['teacher']->fullName] : null,
            'days' => $sessions->map(fn ($s) => $s->starts_at->setTimezone($tz)->dayOfWeekIso)->unique()->sort()->values(),
            'starts_at_time' => $first?->starts_at->setTimezone($tz)->format('H:i'),
            'ends_at_time' => $first?->ends_at->setTimezone($tz)->format('H:i'),
            'timezone' => $tz,
            'total_days' => $sessions->count(),
            'days_done' => $sessions->filter(fn ($s) => $s->ends_at->lte($now))->count(),
            'starts_on' => $this->starts_at?->setTimezone($tz)->toDateString(),
            'ends_on' => $this->ends_at?->setTimezone($tz)->toDateString(),
            'next_day' => $next ? ['day' => $next->day_number, 'starts_at' => $this->lagos($next->starts_at)] : null,
            'status' => $this->phase(),
            'capacity' => $this->capacity,
            'seats_left' => $this->capacity === null ? null : max(0, $this->capacity - $this->context['seats_taken']),
            'price_minor' => $this->price_minor,
            'currency_code' => $this->currency_code,
            'is_free' => $this->isFree(),
            'enrollment_open' => $this->isOpenForEnrollment() && ($this->capacity === null || $this->context['seats_taken'] < $this->capacity),
            'enrolled_learner_ids' => $this->context['enrolled_learner_ids'],
            'cancellation_reason' => $this->when($this->status === TewtoraClass::STATUS_CANCELLED, $this->cancellation_reason),
            'sessions' => $this->when($this->withSessions, fn () => $sessions->map(
                fn ($s) => (new TewtoraClassSessionResource($s, $this->resource, $this->context['link_access']))->resolve($request),
            )->values()),
            ...($this->admin ? [
                'lifecycle' => $this->status,
                'meeting_link' => $this->meeting_link,
                'seats_taken' => $this->context['seats_taken'],
                'published_at' => $this->published_at?->toIso8601ZuluString(),
                'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
                'completed_at' => $this->completed_at?->toIso8601ZuluString(),
                'created_at' => $this->created_at?->toIso8601ZuluString(),
            ] : []),
        ];
    }

    /** "2026-10-13T16:00:00+01:00" — the spec's format: Lagos wall time with its offset. */
    private function lagos(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone(config('classes.display_timezone'))->toIso8601String();
    }
}
