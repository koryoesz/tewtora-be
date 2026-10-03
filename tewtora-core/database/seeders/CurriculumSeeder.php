<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\Curriculum;
use Illuminate\Database\Seeder;

/**
 * Real platform config, not test fixture data — runs in every environment
 * (unlike TestAccountsSeeder, not guarded behind an environment check).
 * Without this, auth.curricula only ever had the single 'ib' row
 * TestAccountsSeeder happened to create as a side effect — meaning
 * production had zero seeded curricula at all, and POST /learners'
 * curriculum validation (Rule::exists) rejected every code the frontend's
 * own "Add a child" form offers except the one TestAccountsSeeder
 * incidentally creates. Confirmed live by the frontend team
 * (docs/needed-endpoints-child-pin-and-budget-schedule.md's "New gap found
 * while verifying"): nigerian/british/american/us_common_core all 422'd.
 * Safe to re-run: updateOrCreate keyed on code.
 */
class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $curricula = [
            ['code' => 'ib', 'display_name' => 'International Baccalaureate'],
            ['code' => 'nigerian', 'display_name' => 'Nigerian'],
            ['code' => 'british', 'display_name' => 'British'],
            ['code' => 'american', 'display_name' => 'American'],
            ['code' => 'us_common_core', 'display_name' => 'US Common Core'],
        ];

        foreach ($curricula as $curriculum) {
            Curriculum::query()->updateOrCreate(
                ['code' => $curriculum['code']],
                ['display_name' => $curriculum['display_name'], 'is_active' => true],
            );
        }
    }
}
