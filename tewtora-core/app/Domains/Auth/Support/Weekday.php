<?php

namespace App\Domains\Auth\Support;

/**
 * Maps the `{day, starts_at, ends_at}` wire shape (mon..sun, same shape
 * auth.assessments.availability already uses — SaveAssessmentDraftRequest)
 * onto auth.teacher_availability's day_of_week TINYINT (0-6, CHECK'd in
 * 2024_02_01_000016), so a PATCH /teachers/:id payload can write into the
 * existing normalized table instead of a second JSON column. 0=Sunday,
 * matching Carbon/MySQL DAYOFWEEK()-1 convention, not ISO-8601.
 */
class Weekday
{
    private const CODES = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
    ];

    public static function toNumber(string $code): int
    {
        return self::CODES[$code];
    }

    public static function toCode(int $number): string
    {
        return array_flip(self::CODES)[$number];
    }
}
