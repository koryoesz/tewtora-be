<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Known-password accounts — never seed these in production.
        if (! app()->environment('production')) {
            $this->call(TestAccountsSeeder::class);
        }
    }
}
