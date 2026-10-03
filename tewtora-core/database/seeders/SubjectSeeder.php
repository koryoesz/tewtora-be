<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Same gap as CurriculumSeeder, same fix: auth.subjects only ever had the
 * single 'mathematics' row TestAccountsSeeder creates as a side effect,
 * which never runs in production — so GET /teachers/{id}/PATCH's
 * Rule::exists(Subject::class, 'code') would reject every other subject
 * the frontend's onboarding wizard offers (lib/onboarding.ts's
 * ONBOARDING_SUBJECTS: Further Maths, Physics, Chemistry, Biology,
 * English, Economics, Coding). Runs in every environment, including
 * production. Safe to re-run: updateOrCreate keyed on code.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['code' => 'mathematics', 'display_name' => 'Mathematics'],
            ['code' => 'further_maths', 'display_name' => 'Further Mathematics'],
            ['code' => 'physics', 'display_name' => 'Physics'],
            ['code' => 'chemistry', 'display_name' => 'Chemistry'],
            ['code' => 'biology', 'display_name' => 'Biology'],
            ['code' => 'english', 'display_name' => 'English'],
            ['code' => 'economics', 'display_name' => 'Economics'],
            ['code' => 'coding', 'display_name' => 'Coding'],
        ];

        foreach ($subjects as $subject) {
            Subject::query()->updateOrCreate(
                ['code' => $subject['code']],
                ['display_name' => $subject['display_name'], 'is_active' => true],
            );
        }
    }
}
