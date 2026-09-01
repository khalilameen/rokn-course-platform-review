<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\CourseCodeSeeder;
use Database\Seeders\Concerns\GuardsDevelopmentFixtures;
use LogicException;
use Tests\TestCase;

final class DemoSeederSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The checked-in vendor autoloader predates the new Database\\Seeders
        // PSR-4 entry. A normal composer install/dump in CI or deploy picks it
        // up; load it directly so this working tree can test the policy now.
        if (!trait_exists(GuardsDevelopmentFixtures::class)) {
            require_once database_path('seeders/Concerns/GuardsDevelopmentFixtures.php');
        }
        if (!class_exists(DatabaseSeeder::class)) {
            require_once database_path('seeders/DatabaseSeeder.php');
        }
        if (!class_exists(CourseCodeSeeder::class)) {
            require_once database_path('seeders/CourseCodeSeeder.php');
        }
    }

    public function test_production_blocks_database_seeder_even_when_fixture_flag_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);

        $this->expectException(LogicException::class);
        (new DatabaseSeeder())->run();
    }

    public function test_direct_fixture_seeder_cannot_bypass_production_gate(): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);

        $this->expectException(LogicException::class);
        (new CourseCodeSeeder())->run();
    }
}
