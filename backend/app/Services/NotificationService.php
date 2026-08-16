<?php

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send notification for a new course lesson.
     *
     * @param Lesson $lesson
     * @param Course $course
     * @return bool
     */
    public static function notifyNewCourseLesson(Lesson $lesson, Course $course): bool
    {
        $titleAr = 'درس جديد متاح';
        $titleEn = 'New Lesson Available';

        $messageAr = "تم إضافة درس جديد: {$lesson->title} في الكورس: " . ($course->name_ar ?? $course->title ?? 'الكورس');
        $messageEn = "A new lesson has been added: {$lesson->title} in course: " . ($course->name_en ?? $course->title ?? 'Course');

        $link = "/Courses/{$course->id}";

        SendStudentNotification::dispatch(
            'new_course_lesson',
            [],
            Lesson::class,
            $lesson->id,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            [],
            null,
            (int) $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED
        );

        return true;
    }

    /**
     * Send notification for a new quiz.
     *
     * @param mixed $quiz
     * @param Course $course
     * @return bool
     */
    public static function notifyNewQuiz($quiz, Course $course): bool
    {
        $titleAr = 'اختبار جديد متاح';
        $titleEn = 'New Quiz Available';

        $quizTitle = $quiz->title ?? 'اختبار جديد';
        $messageAr = "تم إضافة اختبار جديد: {$quizTitle} في الكورس: " . ($course->name_ar ?? $course->title ?? 'الكورس');
        $messageEn = "A new quiz has been added: {$quizTitle} in course: " . ($course->name_en ?? $course->title ?? 'Course');

        $link = "/Courses/{$course->id}";

        SendStudentNotification::dispatch(
            'new_quiz',
            [],
            get_class($quiz),
            $quiz->id,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            [],
            null,
            (int) $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED
        );

        return true;
    }

    /**
     * Send a generic notification to students.
     *
     * @param string $type
     * @param array $userIds Explicit user IDs for a small broadcast; selectors belong in $data
     * @param array $data
     * @return bool
     */
    public static function notifyGeneric(string $type, array $userIds, array $data): bool
    {
        $titleAr = $data['title_ar'] ?? 'إشعار جديد';
        $titleEn = $data['title_en'] ?? 'New Notification';
        $messageAr = $data['message_ar'] ?? '';
        $messageEn = $data['message_en'] ?? '';
        $link = $data['link'] ?? null;
        $notifiableType = $data['notifiable_type'] ?? null;
        $notifiableId = $data['notifiable_id'] ?? null;
        $excludeUserIds = $data['exclude_user_ids'] ?? [];
        $deliveryKey = (string) ($data['delivery_key'] ?? Str::uuid());
        $courseId = isset($data['course_id']) ? (int) $data['course_id'] : null;
        $audience = (string) ($data['audience'] ?? SendStudentNotification::AUDIENCE_ALL);

        SendStudentNotification::dispatch(
            $type,
            $userIds,
            $notifiableType,
            $notifiableId,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            $excludeUserIds,
            $deliveryKey,
            $courseId,
            $audience
        );

        return true;
    }

    /**
     * Send notification for course update.
     *
     * @param Course $course
     * @param string $updateType
     * @return bool
     */
    public static function notifyCourseUpdate(Course $course, string $updateType = 'general'): bool
    {
        $titleAr = 'تحديث في الكورس';
        $titleEn = 'Course Update';

        $messageAr = "تم تحديث الكورس: " . ($course->name_ar ?? $course->title ?? 'الكورس');
        $messageEn = "Course has been updated: " . ($course->name_en ?? $course->title ?? 'Course');

        $link = "/Courses/{$course->id}";

        SendStudentNotification::dispatch(
            'course_update',
            [],
            Course::class,
            $course->id,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            [],
            null,
            (int) $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED
        );

        return true;
    }
}

