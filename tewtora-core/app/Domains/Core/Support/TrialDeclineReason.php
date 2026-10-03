<?php

namespace App\Domains\Core\Support;

/**
 * docs/needed-endpoints-trial-requests.md: a decline reason needs two
 * wordings — the teacher's own candid audit-trail copy, and a softer one
 * that's the only version ever shown to the family. Both wordings live
 * here, keyed by the small fixed code actually stored on
 * core.trial_requests.decline_reason (chk_trial_requests_decline_reason),
 * rather than storing free text per row — one place to fix/tune copy,
 * and no way for a row to end up with mismatched wordings for the same
 * code. TrialRequestResource picks which wording to expose based on
 * whether the viewer is the teacher side or the family side of the
 * request — never both on the same read.
 */
class TrialDeclineReason
{
    public const CODES = ['full', 'level', 'budget', 'other'];

    private const WORDINGS = [
        'full' => [
            'teacher' => 'My schedule has no room for this slot/level right now.',
            'family' => 'This teacher does not have an opening for this time right now.',
        ],
        'level' => [
            'teacher' => "This learner's level or subject isn't a fit for what I teach.",
            'family' => "This isn't the best subject or level match for this teacher.",
        ],
        'budget' => [
            'teacher' => 'The requested budget is below my usual rate.',
            'family' => "This didn't line up with this teacher's available pricing.",
        ],
        'other' => [
            'teacher' => 'Other reason — see my own notes for detail.',
            'family' => 'This teacher is not able to take this trial right now.',
        ],
    ];

    public static function teacherWording(string $code): ?string
    {
        return self::WORDINGS[$code]['teacher'] ?? null;
    }

    public static function familyWording(string $code): ?string
    {
        return self::WORDINGS[$code]['family'] ?? null;
    }
}
