<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One revision protects the complete course authoring aggregate. A module,
 * section, quiz or attachment edit is not independent from the outline the
 * moderator saw when they started editing.
 */
final class CourseAuthoringConcurrencyService
{
    public function lock(Request $request, Course $course): Course
    {
        $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
        $submitted = $request->input('authoring_version');

        if ($submitted === null || (int) $submitted !== (int) $locked->authoring_version) {
            throw ValidationException::withMessages([
                'authoring_version' => [
                    'تغيّر الكورس منذ فتح هذه الصفحة\nأعد تحميلها ثم راجع التعديل قبل الحفظ',
                ],
            ])->status(409);
        }

        return $locked;
    }

    public function advance(Course $course): int
    {
        $course->increment('authoring_version');

        return (int) $course->authoring_version;
    }

    public function lockExpected(Course $course, int $expectedVersion): Course
    {
        $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
        if ((int) $locked->authoring_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'authoring_version' => [
                    'تغيّر الكورس أثناء الحفظ\nراجع آخر تعديل قبل النشر',
                ],
            ])->status(409);
        }

        return $locked;
    }
}
