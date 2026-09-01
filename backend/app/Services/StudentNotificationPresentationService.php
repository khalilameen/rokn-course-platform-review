<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\AdminNotification;
use App\Models\Lesson;
use App\Models\StudentNotification;
use App\Support\RoknAppLink;
use Illuminate\Support\Facades\Schema;

final class StudentNotificationPresentationService
{
    /** @var array<string, AdminNotification>|null */
    private ?array $templatesByType = null;

    /** @return array{notification_type:string,course_id:?int,image_url:?string,action_label_ar:string,action_label_en:string,link:?string} */
    public function for(StudentNotification $notification): array
    {
        $course = $this->courseFor($notification);
        $courseBound = in_array($notification->notifiable_type, [Course::class, Lesson::class], true);
        $courseUnavailable = $courseBound
            && (
                !$course
                || !$course->isPublishedForLearning()
                || $course->isNestedCourse()
            );
        if ($courseUnavailable) {
            $course = null;
        }
        $courseId = $course ? (int) $course->id : null;
        $type = (string) $notification->notification_type;
        $template = $this->templateFor($type);
        [$fallbackActionAr, $fallbackActionEn] = $this->actions($type);
        $actionAr = trim((string) $notification->action_label_ar)
            ?: trim((string) $template?->action_label_ar)
            ?: $fallbackActionAr;
        $actionEn = trim((string) $notification->action_label_en)
            ?: trim((string) $template?->action_label_en)
            ?: $fallbackActionEn;
        $explicitImage = $this->safeImageUrl($notification->image_url);
        $templateImage = $this->safeImageUrl($template?->image);
        $explicitLink = !$courseUnavailable && trim((string) $notification->link) !== ''
            ? $notification->link
            : $template?->link;
        if ($courseUnavailable) {
            $explicitLink = null;
        }

        return [
            'notification_type' => $type,
            'course_id' => $courseId,
            'image_url' => $explicitImage ?: $templateImage ?: $this->safeImageUrl($course?->image),
            'action_label_ar' => $actionAr,
            'action_label_en' => $actionEn,
            'link' => $this->safeLink($explicitLink, $type, $courseId),
        ];
    }

    private function templateFor(string $type): ?AdminNotification
    {
        if ($this->templatesByType === null) {
            $this->templatesByType = [];
            if (
                Schema::hasTable('admin_notifications')
                && Schema::hasColumn('admin_notifications', 'system_key')
            ) {
                AdminNotification::query()
                    ->available()
                    ->whereNotNull('system_key')
                    ->get()
                    ->each(function (AdminNotification $template): void {
                        $key = trim((string) $template->system_key);
                        if ($key !== '' && !isset($this->templatesByType[$key])) {
                            $this->templatesByType[$key] = $template;
                        }
                    });
            }
        }

        return $this->templatesByType[$type] ?? null;
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
            'coins_claimed', 'package_purchased', 'coin_reward', 'whatsapp_connected' => ['افتح المحفظة', 'View balance'],
            'coin_offer' => ['افتح العرض', 'View offer'],
            'learning_nudge', 'continue_course', 'course_enrolled', 'institutional_grant' => ['أكمل من مكانك', 'Continue learning'],
            'course_promotion' => ['تفاصيل الكورس', 'View course'],
            'new_course' => ['افتح الكورس', 'View new course'],
            'project_update' => ['افتح النتيجة', 'View result'],
            'certificate_ready', 'course_completed' => ['افتح الشهادة', 'View certificate'],
            'support_case_update' => ['افتح البلاغ', 'View case'],
            default => ['افتح ركن', 'Open Rokn'],
        };
    }

    private function safeLink(?string $link, string $type, ?int $courseId): ?string
    {
        if (in_array($type, ['coins_claimed', 'package_purchased', 'coin_reward', 'coin_offer', 'whatsapp_connected'], true)) {
            return 'rokn://wallet';
        }
        if ($courseId !== null) {
            if (in_array($type, ['learning_nudge', 'continue_course', 'course_enrolled', 'institutional_grant'], true)) {
                return RoknAppLink::course($courseId, true);
            }
            if (in_array($type, ['course_promotion', 'new_course', 'new_course_lesson', 'course_update'], true)) {
                return RoknAppLink::course($courseId);
            }
        }

        $link = trim((string) $link);
        if ($link === '') {
            return in_array($type, ['certificate_ready', 'course_completed', 'project_update'], true)
                ? 'rokn://profile'
                : 'rokn://home';
        }
        return RoknAppLink::normalize($link);
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
