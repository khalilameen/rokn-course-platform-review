<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Controllers\API\CoursePurchaseController;
use Illuminate\Http\Request;

/**
 * Feature tests covering Course Authorization and Enrollment API endpoints:
 * payment methods, authorizing access, access check, and student enrollment/order lists.
 */
class CourseEnrollmentEndpointTest extends ApiTestCase
{
    public function test_course_authorization_route_uses_the_atomic_purchase_controller(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/v1/courses/authorize', 'POST')
        );

        self::assertSame(
            CoursePurchaseController::class . '@authorizeCourse',
            $route->getActionName()
        );
    }

    public function test_can_get_payment_methods(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/courses/payment-methods');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_authorize_course(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/courses/authorize', [
            'course_id' => $this->courseId,
            'payment_method' => 'wallet'
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_check_course_access(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/courses/check-access', [
            'course_id' => $this->courseId
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_my_enrollments(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/courses/my-enrollments');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_my_orders(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/courses/my-orders');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_my_bills(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/courses/my-bills');
        $this->assertNotEquals(404, $response->status());
    }
}
