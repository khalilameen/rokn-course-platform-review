<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\UsersController;
use App\Jobs\SendStudentNotification;
use App\Jobs\SendUserPushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\Feature\API\ApiTestCase;

final class AdminNotificationParityTest extends ApiTestCase
{
    public function test_direct_notification_persists_title_and_message_then_queues_push(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        $this->user->forceFill(['notifications_status' => true]);
        $this->user->deviceTokens()->create([
            'device_token' => 'test-device-token',
            'device_type' => 'android',
            'device_os' => 'android',
        ]);

        $request = Request::create('/admin/users/1/send_notification', 'POST', [
            'title' => 'عنوان مهم',
            'message' => 'رسالة الإشعار نفسها',
        ]);

        $response = app(UsersController::class)->sendNotification($request, $this->user);

        self::assertSame(302, $response->getStatusCode());
        self::assertTrue(session()->has('success'));
        $this->assertDatabaseHas('student_notifications', [
            'user_id' => $this->user->id,
            'notification_type' => 'admin_message',
            'title_ar' => 'عنوان مهم',
            'title_en' => 'عنوان مهم',
            'message_ar' => 'رسالة الإشعار نفسها',
            'message_en' => 'رسالة الإشعار نفسها',
        ]);
        Queue::assertPushed(SendUserPushNotification::class, 1);
    }

    public function test_broadcast_preserves_distinct_arabic_and_english_copy(): void
    {
        Queue::fake([SendStudentNotification::class]);

        $request = Request::create('/admin/notifications', 'POST', [
            'title_ar' => 'عنوان عربي',
            'message_ar' => 'رسالة عربية',
            'title_en' => 'English title',
            'message_en' => 'English message',
            'audience' => 'all',
        ]);

        app(NotificationsController::class)->store($request);

        Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
            return $this->jobProperty($job, 'titleAr') === 'عنوان عربي'
                && $this->jobProperty($job, 'messageAr') === 'رسالة عربية'
                && $this->jobProperty($job, 'titleEn') === 'English title'
                && $this->jobProperty($job, 'messageEn') === 'English message';
        });
    }

    public function test_legacy_arabic_form_fields_remain_supported(): void
    {
        Queue::fake([SendStudentNotification::class]);

        $request = Request::create('/admin/notifications', 'POST', [
            'title' => 'عنوان قديم',
            'message' => 'رسالة قديمة',
            'audience' => 'all',
        ]);

        app(NotificationsController::class)->store($request);

        Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
            return $this->jobProperty($job, 'titleAr') === 'عنوان قديم'
                && $this->jobProperty($job, 'titleEn') === 'عنوان قديم'
                && $this->jobProperty($job, 'messageAr') === 'رسالة قديمة'
                && $this->jobProperty($job, 'messageEn') === 'رسالة قديمة';
        });
    }

    private function jobProperty(SendStudentNotification $job, string $name): mixed
    {
        $property = new ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
