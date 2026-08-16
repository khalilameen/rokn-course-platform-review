<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;

final readonly class CourseDurationService
{
    /** Lesson durations take precedence over the legacy course-hours fallback. */
    public function minutes(Course $course): int
    {
        $lessonIds = $course->relationLoaded('sections')
            ? $course->sections
                ->where('sectionable_type', Lesson::class)
                ->pluck('sectionable_id')
            : CourseSection::query()
                ->where('course_id', $course->getKey())
                ->where('sectionable_type', Lesson::class)
                ->pluck('sectionable_id');

        $lessons = Lesson::query()
            ->with('mediaState:id,lesson_id,duration_seconds')
            ->whereIn('id', $lessonIds->filter()->unique()->values())
            ->get(['id', 'duration_minutes']);

        $lessonMinutes = (int) $lessons->sum(function (Lesson $lesson): int {
            $declaredMinutes = max(0, (int) $lesson->duration_minutes);
            if ($declaredMinutes > 0) {
                return $declaredMinutes;
            }

            $providerSeconds = max(0, (int) ($lesson->mediaState?->duration_seconds ?? 0));

            return $providerSeconds > 0 ? (int) ceil($providerSeconds / 60) : 0;
        });

        if ($lessonMinutes > 0) {
            return $lessonMinutes;
        }

        return max(0, (int) $course->hours_count) * 60;
    }

    public function attach(Course $course): Course
    {
        $course->setAttribute('duration_minutes_computed', $this->minutes($course));

        return $course;
    }
}
