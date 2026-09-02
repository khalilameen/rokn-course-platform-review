<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ModeratorPlatformConfigurationBoundaryTest extends TestCase
{
    public function test_moderator_can_read_levels_but_cannot_change_the_global_progression_ladder(): void
    {
        $matrix = app(AdminPermissionMatrix::class);
        $index = Route::getRoutes()->getByName('admin.levels.index');

        self::assertNotNull($index);
        self::assertSame(['GET', 'HEAD'], $index->methods());
        self::assertNotContains('admin.only', $index->gatherMiddleware());
        self::assertTrue($matrix->allows('moderator', 'admin.levels.index', 'GET'));

        $mutations = [
            'admin.levels.create' => 'GET',
            'admin.levels.store' => 'POST',
            'admin.levels.edit' => 'GET',
            'admin.levels.update' => 'PATCH',
            'admin.levels.destroy' => 'DELETE',
        ];

        foreach ($mutations as $name => $method) {
            $route = Route::getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains('admin.only', $route->gatherMiddleware(), $name);
            self::assertContains('admin.mfa', $route->gatherMiddleware(), $name);
            self::assertFalse($matrix->allows('moderator', $name, $method), $name);
        }

        self::assertNull(Route::getRoutes()->getByName('admin.levels.show'));
    }
}
