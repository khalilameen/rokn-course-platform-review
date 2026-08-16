<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationsController;
use App\Jobs\SendStudentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;

final class AdminNotificationParityTest extends TestCase
{
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
