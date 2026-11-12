<?php

namespace App\Domains\Core\Support;

use Carbon\CarbonImmutable;

/**
 * Turns a weekly pattern ("Tuesdays & Thursdays, 16:00–17:00, 12 days from
 * 13 Oct") into dated class days. Times are wall-clock in the class
 * timezone (Lagos) and stored as UTC — the same model as tewtora-fe's
 * buildClass(), so the backend's days line up with what the UI expects.
 */
final class ClassScheduleGenerator
{
    /**
     * @param  list<int>  $weekdays  ISO weekdays, Monday = 1
     * @return list<array{starts_at: string, ends_at: string}> UTC, in date order
     */
    public static function generate(string $startsOn, array $weekdays, string $startTime, string $endTime, int $totalDays, string $timezone): array
    {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        $day = CarbonImmutable::parse($startsOn, $timezone)->startOfDay();
        $sessions = [];

        // totalDays is capped by validation; the guard stops a bad pattern spinning forever.
        for ($guard = 0; count($sessions) < $totalDays && $guard < 3700; $guard++, $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            $sessions[] = [
                'starts_at' => CarbonImmutable::parse($day->toDateString().' '.$startTime, $timezone)->utc()->toIso8601String(),
                'ends_at' => CarbonImmutable::parse($day->toDateString().' '.$endTime, $timezone)->utc()->toIso8601String(),
            ];
        }

        return $sessions;
    }
}
