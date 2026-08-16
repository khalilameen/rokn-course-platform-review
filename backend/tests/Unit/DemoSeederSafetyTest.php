<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoknExperienceDemoSeeder;
use Mockery;
use Tests\TestCase;

final class DemoSeederSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The checked-in vendor autoloader predates the new Database\\Seeders
        // PSR-4 entry. A normal composer install/dump in CI or deploy picks it
        // up; load it directly so this working tree can test the policy now.
        if (!class_exists(DatabaseSeeder::class)) {
            require_once database_path('seeders/DatabaseSeeder.php');
        }
    }

    public function test_production_blocks_demo_seed_without_the_production_opt_in(): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);
        config()->set('demo.allow_in_production', false);

        $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
        $seeder->shouldReceive('call')->never();

        $seeder->run();
    }

    public function test_production_demo_seed_requires_both_explicit_switches(): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);
        config()->set('demo.allow_in_production', true);

        $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
        $seeder->shouldReceive('call')
            ->once()
            ->with(RoknExperienceDemoSeeder::class);

        $seeder->run();
    }
}
