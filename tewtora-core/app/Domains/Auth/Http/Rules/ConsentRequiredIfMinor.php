<?php

namespace App\Domains\Auth\Http\Rules;

use App\Domains\Auth\Models\LearnerProfile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * backend-engineering-standards.md §9's own worked example. Mirrors the
 * DB-level consent trigger (2024_02_01_000014/000054) at the application
 * layer, so a bad request gets a clean 422 instead of surfacing the
 * trigger's SIGNAL as a raw SQL error.
 */
class ConsentRequiredIfMinor implements ValidationRule
{
    public function __construct(private readonly LearnerProfile $learnerProfile) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->learnerProfile->profile_type === 'child' && ! $value) {
            $fail('Parental consent is required for a child profile.');
        }
    }
}
