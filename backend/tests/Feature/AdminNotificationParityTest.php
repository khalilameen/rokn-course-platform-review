<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\UsersController;
use App\Jobs\SendStudentNotification;
use App\Jobs\SendUserPushNotification;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use App\Services\StudentNotificationService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\Feature\API\ApiTestCase;

final class AdminNotificationParityTest extends ApiTestCase
{
    public function test_queue_outage_keeps_the_durable_inbox_notification(): void
    {
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $notification = DB::transaction(fn () => StudentNotificationService::notifyUser(
            $this->user,
            'course_enrolled',
            'الكورس جاهز',
            'Course ready',
            'ابدأ الآن',
            'Start now',
            null,
            null,
            null,
            'queue-outage-durable-inbox'
        ));

        self::assertNotNull($notification);
        $this->assertDatabaseHas('student_notifications', [
            'id' => $notification->id,
            'user_id' => $this->user->id,
            'delivery_key' => 'queue-outage-durable-inbox',
            'push_attempted_at' => null,
        ]);
    }

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
            'authoring_request_id' => (string) Str::uuid(),
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
            'authoring_request_id' => (string) Str::uuid(),
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
            'authoring_request_id' => (string) Str::uuid(),
        ]);

        app(NotificationsController::class)->store($request);

        Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
            return $this->jobProperty($job, 'titleAr') === 'عنوان قديم'
                && $this->jobProperty($job, 'titleEn') === 'عنوان قديم'
                && $this->jobProperty($job, 'messageAr') === 'رسالة قديمة'
                && $this->jobProperty($job, 'messageEn') === 'رسالة قديمة';
        });
    }

    public function test_failed_campaign_can_be_retried_once_without_changing_its_delivery_key(): void
    {
        Queue::fake([SendStudentNotification::class]);
        $migration = require database_path('migrations/2026_09_01_000010_create_notification_campaigns_table.php');
        $migration->up();
        try {
            $campaign = NotificationCampaign::query()->create([
                'delivery_key' => 'failed-campaign-1',
                'notification_type' => 'admin_broadcast',
                'audience' => SendStudentNotification::AUDIENCE_ALL,
                'user_ids' => [],
                'exclude_user_ids' => [],
                'title_ar' => 'عنوان',
                'title_en' => 'Title',
                'message_ar' => 'رسالة',
                'message_en' => 'Message',
                'status' => NotificationCampaign::STATUS_FAILED,
                'retry_count' => 3,
                'failed_at' => now(),
                'failure_code' => 'recovery_exhausted',
                'queued_at' => now()->subHour(),
            ]);

            $service = app(NotificationCampaignService::class);
            self::assertTrue($service->retry($campaign));
            self::assertFalse($service->retry($campaign));

            $campaign->refresh();
            self::assertSame(NotificationCampaign::STATUS_QUEUED, $campaign->status);
            self::assertSame(0, $campaign->retry_count);
            self::assertNull($campaign->failed_at);
            self::assertNull($campaign->failure_code);
            Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
                return $job->uniqueId() === 'failed-campaign-1';
            });
        } finally {
            $migration->down();
        }
    }

    private function jobProperty(SendStudentNotification $job, string $name): mixed
    {
        $property = new ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
