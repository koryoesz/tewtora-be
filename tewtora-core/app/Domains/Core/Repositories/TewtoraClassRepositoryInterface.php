<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Models\TewtoraClassSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface TewtoraClassRepositoryInterface
{
    /**
     * @param  list<array{starts_at: string, ends_at: string, meeting_link?: ?string}>  $sessions
     */
    public function create(array $attributes, array $sessions): TewtoraClass;

    /**
     * $sessions null leaves the schedule alone; an array replaces it,
     * renumbers the days by date and re-derives the class's starts_at/ends_at.
     *
     * @param  list<array{starts_at: string, ends_at: string, meeting_link?: ?string}>|null  $sessions
     */
    public function update(TewtoraClass $class, array $attributes, ?array $sessions = null): TewtoraClass;

    /** Edits one class day (its link, or its times — which renumbers the days). */
    public function updateSession(TewtoraClassSession $session, array $attributes): TewtoraClassSession;

    /** Re-reads the class with a row lock — call inside a transaction. */
    public function lockForUpdate(TewtoraClass $class): TewtoraClass;

    /**
     * What families browse: published and finished classes, never drafts or
     * cancelled ones, soonest first.
     *
     * @param  array{phase?: ?string, subject_id?: ?int, curriculum_id?: ?int, level?: ?string}  $filters
     */
    public function catalogue(array $filters = []): Collection;

    /** Class days of catalogue classes starting within [$from, $to), with their class loaded. */
    public function sessionsBetween(CarbonInterface $from, CarbonInterface $to): Collection;

    public function forAdmin(?string $status = null): Collection;

    /** Published classes whose last day has ended. */
    public function finished(): Collection;
}
