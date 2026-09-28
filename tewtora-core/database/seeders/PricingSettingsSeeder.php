<?php

namespace Database\Seeders;

use App\Domains\Core\Models\PricingSetting;
use Illuminate\Database\Seeder;

/**
 * Real platform config, not test fixture data — runs in every environment
 * (unlike TestAccountsSeeder, not guarded behind an environment check).
 * Sets the current rate to the frontend's own placeholder values, which
 * are confirmed to be the actual intended uniform-per-format rate for now
 * (see docs/frontend-integration-guide.md §5). Safe to re-run:
 * updateOrCreate keyed on format.
 */
class PricingSettingsSeeder extends Seeder
{
    public function run(): void
    {
        PricingSetting::query()->updateOrCreate(
            ['format' => 'one_on_one'],
            ['rate_minor' => 500000, 'currency_code' => 'NGN'],
        );

        PricingSetting::query()->updateOrCreate(
            ['format' => 'group'],
            ['rate_minor' => 250000, 'currency_code' => 'NGN'],
        );
    }
}
