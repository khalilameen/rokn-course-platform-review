<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminAuthorizationMatrixTest extends TestCase
{
    public function test_moderator_permissions_are_an_explicit_fail_closed_allow_list(): void
    {
        $matrix = app(AdminPermissionMatrix::class);

        self::assertTrue($matrix->allows('moderator', 'admin.courses.index', 'GET'));
        self::assertTrue($matrix->allows('moderator', 'admin.courses.update', 'PATCH'));
        self::assertTrue($matrix->allows('moderator', 'admin.courses.sections.update', 'PATCH'));

        self::assertFalse($matrix->allows('moderator', 'admin.project-submissions.reject', 'POST'));
        self::assertFalse($matrix->allows('moderator', 'admin.settings', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.index', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.resolve', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.ignore', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.reopen', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.future-route', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.project-submissions.future-action', 'POST'));
        self::assertFalse($matrix->allows('moderator', null, 'GET'));
        self::assertFalse($matrix->allows('client', 'admin.courses.index', 'GET'));

        self::assertTrue($matrix->allows('admin', 'admin.future-route', 'DELETE'));
    }

    public function test_sensitive_routes_require_an_administrator_and_all_dashboard_routes_require_mfa(): void
    {
        foreach ([
            'admin.settings',
            'admin.orders.index',
            'admin.courses.destroy',
            'admin.student-progress.index',
            'admin.project-submissions.index',
            'admin.exam-results.index',
            'admin.exam-results.export',
            'admin.users.index',
            'admin.product-operations.features.update',
            'admin.payment-reconciliation-findings.index',
            'admin.payment-reconciliation-findings.resolve',
            'admin.payment-reconciliation-findings.ignore',
            'admin.payment-reconciliation-findings.reopen',
            'admin.moderators.index',
            'admin.operating-costs.index',
            'admin.operating-costs.store',
            'admin.operating-costs.report',
            'admin.operating-costs.report.export',
            'admin.courses.commercial-report.export',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Missing expected route {$name}");
            self::assertContains('admin.only', $route->gatherMiddleware(), "{$name} must require admin.only");
            self::assertContains('admin.mfa', $route->gatherMiddleware(), "{$name} must require admin MFA");
        }

        $moderatorRoute = Route::getRoutes()->getByName('admin.courses.update');
        self::assertNotNull($moderatorRoute);
        self::assertNotContains('admin.only', $moderatorRoute->gatherMiddleware());
        self::assertContains('admin.mfa', $moderatorRoute->gatherMiddleware());
    }

    public function test_every_moderator_matrix_entry_names_a_real_route_and_method(): void
    {
        $matrix = app(AdminPermissionMatrix::class);

        foreach ($matrix->moderatorRules() as $name => $allowedMethods) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Stale moderator permission: {$name}");
            $routeMethods = array_values(array_diff($route->methods(), ['HEAD']));

            foreach ($allowedMethods as $method) {
                self::assertContains($method, $routeMethods, "{$name} does not support {$method}");
            }
        }
    }

    public function test_moderator_navigation_does_not_render_administrator_only_links(): void
    {
        $moderator = new User(['name' => 'Content Moderator']);
        $moderator->role = 'moderator';
        $this->app['auth']->guard()->setUser($moderator);

        $html = view('admin.includes.aside')->render();

        self::assertStringContainsString(route('admin.courses.index'), $html);
        self::assertStringContainsString(route('admin.teachers.index'), $html);
        self::assertStringNotContainsString(route('admin.settings'), $html);
        self::assertStringNotContainsString(route('admin.orders.index'), $html);
        self::assertStringNotContainsString(route('admin.payment-reconciliation-findings.index'), $html);
        self::assertStringNotContainsString(route('admin.users.index'), $html);
        self::assertStringNotContainsString(route('admin.student-progress.index'), $html);
        self::assertStringNotContainsString(route('admin.project-submissions.index'), $html);
    }
}
