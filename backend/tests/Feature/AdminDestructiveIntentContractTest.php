<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\UsersController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminDestructiveIntentContractTest extends TestCase
{
    public function test_student_account_deletion_only_uses_the_verified_request_workflow(): void
    {
        self::assertNull(
            Route::getRoutes()->getByName('admin.users.destroy'),
            'A generic resource delete would bypass identity verification and deletion evidence.'
        );
        self::assertFalse(
            method_exists(UsersController::class, 'destroy'),
            'Keep the irreversible delete operation out of the generic user controller.'
        );

        $route = Route::getRoutes()->getByName('admin.contacts.execute-account-deletion');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertContains('admin.only', $route->gatherMiddleware());
        self::assertContains('admin.audit', $route->gatherMiddleware());
        self::assertContains('throttle:6,1', $route->gatherMiddleware());
    }
}
