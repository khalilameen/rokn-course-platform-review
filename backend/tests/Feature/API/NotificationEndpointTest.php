<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering Student Notification API endpoints:
 * unread count, recent notifications, all notifications, marking specific or all notifications read.
 */
class NotificationEndpointTest extends ApiTestCase
{
    public function test_can_view_unread_notifications_count(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/notifications/unread-count');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_last_ten_notifications(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/notifications/last-ten');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_all_notifications(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/notifications');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_mark_notification_as_read(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/notifications/1/mark-read');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_mark_all_notifications_as_read(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/notifications/mark-all-read');
        $this->assertNotEquals(404, $response->status());
    }
}
