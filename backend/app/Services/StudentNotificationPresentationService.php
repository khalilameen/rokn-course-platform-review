<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\StudentNotification;

final class StudentNotificationPresentationService
{
    /** @return array{notification_type:string,course_id:?int,image_url:?string,action_label_ar:string,action_label_en:string,link:?string} */
    public function for(StudentNotification $notification): array
    {
        $course = $this->courseFor($notification);
        $courseId = $course ? (int) $course->id : null;
        $type = (string) $notification->notification_type;
        [$actionAr, $actionEn] = $this->actions($type);

        return [
            'notification_type' => $type,
            'course_id' => $courseId,
            'image_url' => $this->safeImageUrl($course?->image),
            'action_label_ar' => $actionAr,
            'action_label_en' => $actionEn,
            'link' => $this->safeLink($notification->link, $type, $courseId),
        ];
    }

    private function courseFor(StudentNotification $notification): ?Course
    {
        $notifiable = $notification->relationLoaded('notifiable')
            ? $notification->notifiable
            : null;

        if ($notifiable instanceof Course) {
            return $notifiable;
        }
        if ($notifiable instanceof Lesson) {
            return $notifiable->relationLoaded('course')
                ? $notifiable->course
                : $notifiable->course()->first();
        }
        if ($notification->notifiable_type === Course::class && $notification->notifiable_id) {
            return Course::query()->find((int) $notification->notifiable_id);
        }

        return null;
    }

    /** @return array{string,string} */
    private function actions(string $type): array
    {
        return match ($type) {
            'coins_claimed', 'package_purchased', 'coin_reward' => ['افتح المحفظة', 'View balance'],
            'coin_offer' => ['افتح العرض', 'View offer'],
            'learning_nudge', 'continue_course', 'course_enrolled' => ['أكمل من مكانك', 'Continue learning'],
            'course_promotion' => ['تفاصيل الكورس', 'View course'],
            'new_course' => ['افتح الكورس', 'View new course'],
            'project_update' => ['افتح النتيجة', 'View result'],
            'certificate_ready', 'course_completed' => ['افتح الشهادة', 'View certificate'],
            default => ['افتح ركن', 'Open Rokn'],
        };
    }

    private function safeLink(?string $link, string $type, ?int $courseId): ?string
    {
        if (in_array($type, ['coins_claimed', 'package_purchased', 'coin_reward', 'coin_offer'], true)) {
            return 'rokn://wallet';
        }
        if ($courseId !== null) {
            if (in_array($type, ['learning_nudge', 'continue_course', 'course_enrolled'], true)) {
                return "rokn://course/{$courseId}/watch";
            }
            if (in_array($type, ['course_promotion', 'new_course', 'new_course_lesson', 'course_update'], true)) {
                return "rokn://course/{$courseId}";
            }
        }

        $link = trim((string) $link);
        if ($link === '') {
            return in_array($type, ['certificate_ready', 'course_completed', 'project_update'], true)
                ? 'rokn://profile'
                : null;
        }
        if (preg_match('#^(?:rokn://|/(?:courses?|wallet|profile)(?:/|$)|https://(?:www\.)?rokn\.(?:app|com)(?:/|$))#i', $link)) {
            return $link;
        }

        return null;
    }

    private function safeImageUrl(mixed $value): ?string
    {
        $image = trim((string) $value);
        if ($image === '') {
            return null;
        }
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return str_starts_with(strtolower($image), 'https://') ? $image : null;
        }

        $base = rtrim((string) config('app.url'), '/');
        return str_starts_with(strtolower($base), 'https://')
            ? $base . '/' . ltrim($image, '/')
            : null;
    }
}
