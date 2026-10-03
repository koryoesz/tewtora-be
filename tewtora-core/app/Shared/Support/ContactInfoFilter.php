<?php

namespace App\Shared\Support;

/**
 * The one place the "does this text contain contact info" pattern is
 * defined — shared by Core's MessageRedactor (strips it from a message body)
 * and Auth's teacher-bio validation (rejects the bio outright instead of
 * silently stripping it, since a bio is authored once and reviewed, not a
 * live conversation). Keeping a single pattern here means a gap found in
 * one context gets fixed for both instead of two regexes drifting apart.
 */
class ContactInfoFilter
{
    public const PATTERN = '/(\+?\d[\d\s\-]{8,}\d)|(\b[\w.]+@[\w.]+\b)|(https?:\/\/\S+)|(\bwa\.me\S*)/i';

    public static function containsContactInfo(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}
