<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Controllers\API\CoursePurchaseController;
use App\Models\PaymentMethod;
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
        PaymentMethod::query()->create([
            'name' => 'غير جاهزة',
            'account_details' => PaymentMethod::DEFAULT_ACCOUNT_DETAILS,
            'is_active' => true,
            'is_default' => false,
        ]);
        PaymentMethod::query()->create([
            'name' => 'طريقة جاهزة',
            'account_details' => 'بيانات دفع صحيحة',
            'is_active' => true,
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/courses/payment-methods')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        self::assertTrue($names->contains('طريقة جاهزة'));
        self::assertFalse($names->contains('غير جاهزة'));
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
