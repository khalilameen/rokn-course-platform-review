<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\GuardsDevelopmentFixtures;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use GuardsDevelopmentFixtures;

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->guardDevelopmentFixtures();

        $this->call(RoknExperienceDemoSeeder::class);
    }
}
