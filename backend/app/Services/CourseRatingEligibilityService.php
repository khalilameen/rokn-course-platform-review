<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\User;

final readonly class CourseRatingEligibilityService
{
    public function __construct(private CourseChatAccessService $courseAccess)
    {
    }

    /** @return array{can_rate: bool, reason: string} */
    public function for(
        User $user,
        Course $course,
        ?bool $hasLearningAccess = null
    ): array
    {
        if (
            $course->isNestedCourse()
            || !$course->isPublishedForLearning()
            || !($hasLearningAccess
                ?? $this->courseAccess->hasLearningAccess((int) $user->id, (int) $course->id))
        ) {
            return ['can_rate' => false, 'reason' => 'course_access_required'];
        }

        $lessonSectionIds = $course->relationLoaded('sections')
            ? $course->sections
                ->where('sectionable_type', Lesson::class)
                ->pluck('id')
            : $course->sections()
                ->where('sectionable_type', Lesson::class)
                ->pluck('id');

        if ($lessonSectionIds->isEmpty()) {
            return ['can_rate' => false, 'reason' => 'watch_required'];
        }

        $hasVerifiedWatch = LessonWatchEvidence::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $lessonSectionIds)
            ->whereNotNull('completed_at')
            ->exists();

        return [
            'can_rate' => $hasVerifiedWatch,
            'reason' => $hasVerifiedWatch ? 'eligible' : 'watch_required',
        ];
    }
}
