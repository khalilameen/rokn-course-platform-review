<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        if (!config('demo.seed_enabled', false)) {
            $this->command?->info('Demo experience seeding is disabled.');
            return;
        }

        if (app()->environment('production') && !config('demo.allow_in_production', false)) {
            $this->command?->warn(
                'Demo seeding was blocked in production. Set ROKN_ALLOW_PRODUCTION_DEMO_SEED=true explicitly to opt in.'
            );
            return;
        }

        $this->call(RoknExperienceDemoSeeder::class);
    }
}
