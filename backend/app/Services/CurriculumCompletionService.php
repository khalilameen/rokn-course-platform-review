<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\StudentSectionProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the irreversible boundary between mutable course authoring and a
 * learner's earned completion.
 */
final class CurriculumCompletionService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence
    ) {
    }

    public function markCompleted(
        int $userId,
        int $courseId,
        ?int $requestedRevision = null
    ): ?int {
        return DB::transaction(function () use ($userId, $courseId, $requestedRevision): ?int {
            $course = Course::query()->whereKey($courseId)->lockForUpdate()->first();
            if (!$course) {
                return null;
            }

            $revision = max(
                1,
                (int) ($requestedRevision
                    ?: $course->last_published_authoring_version
                    ?: $course->authoring_version
                    ?: 1)
            );

            // Rolling-deploy compatibility: version the signal immediately;
            // persistence starts as soon as the additive migration is present.
            if (!Schema::hasColumns('course_enrollments', [
                'completed_curriculum_revision',
                'curriculum_completed_at',
            ])) {
                return $revision;
            }

            $enrollment = CourseEnrollment::query()
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();
            if (!$enrollment) {
                return null;
            }

            $earnedRevision = (int) ($enrollment->completed_curriculum_revision ?? 0);
            if ($earnedRevision > 0) {
                return $earnedRevision;
            }

            // The durable marker is itself the authority. Never trust a
            // caller merely because it named its signal `course.completed`.
            $learningSectionIds = $this->sectionSequence->learning(
                CourseSection::query()->where('course_id', $courseId)->get()
            )->pluck('id');
            if ($learningSectionIds->isEmpty()) {
                return null;
            }
            $completedSections = StudentSectionProgress::query()
                ->where('user_id', $userId)
                ->whereIn('course_section_id', $learningSectionIds)
                ->where('is_completed', true)
                ->distinct('course_section_id')
                ->count('course_section_id');
            if ($completedSections !== $learningSectionIds->count()) {
                return null;
            }

            $enrollment->forceFill([
                'completed_curriculum_revision' => $revision,
                'curriculum_completed_at' => now(),
            ])->save();

            return $revision;
        }, 3);
    }

    public function earnedRevision(CourseEnrollment $enrollment): ?int
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            return null;
        }

        $revision = (int) ($enrollment->completed_curriculum_revision ?? 0);

        return $revision > 0 ? $revision : null;
    }
}
