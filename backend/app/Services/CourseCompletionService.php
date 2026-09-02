<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\StudentSectionProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CourseCompletionService
{
    public function __construct(
        private CourseReadCompatibilityService $courseReads,
        private CoursePresentationService $coursePresentation,
        private LearningEvidenceService $learningEvidence,
        private CourseModuleAccessService $courseAccess,
        private InternalSignalService $internalSignals,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    /**
     * @return array{success:bool,status:int,message:string,data:mixed,code?:string}
     */
    public function complete(User $user, int $courseId, int $sectionId): array
    {
        $course = Course::findOrFail($courseId);
        $section = CourseSection::query()
            ->whereKey($sectionId)
            ->where('course_id', $courseId)
            ->first();

        if (!$section) {
            return $this->failure(404, 'Section not found in this course');
        }
        if (!$this->courseReads->hasLearningAccess((int) $user->id, $courseId)) {
            return $this->failure(403, 'You are not authorized to access this course');
        }

        $existingProgress = $this->revisionReads->completedSectionProgress(
            (int) $user->id,
            $sectionId
        );

        if ($section->getSectionType() === 'project') {
            return $this->failure(
                409,
                'Submit the project before continuing',
                'project_submission_required'
            );
        }

        if ($section->getSectionType() === 'lesson') {
            $lesson = Lesson::with('courseSection')->find($section->sectionable_id);
            if (!$lesson) {
                return $this->failure(
                    409,
                    'Open this lesson and try again',
                    'lesson_evidence_unavailable'
                );
            }

            $evidence = $this->learningEvidence->evidenceFor($user, $lesson);
            if (!$evidence['eligible_for_completion']) {
                return $this->failure(
                    409,
                    'Continue this lesson before moving to the next step',
                    'verified_watch_required',
                    ['learning_evidence' => $evidence]
                );
            }
        }

        if ($section->getSectionType() === 'quiz' && !$this->hasPassedQuiz($user, $course, $section)) {
            return $this->failure(
                409,
                'Pass this assessment before continuing',
                'passed_quiz_required'
            );
        }

        if ($existingProgress && $existingProgress->is_completed) {
            $courseProgress = DB::transaction(function () use ($user, $courseId): array {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                return $this->recordCourseCompletionIfEligible(
                    (int) $user->id,
                    $courseId
                );
            }, 3);

            return $this->success(
                'Section already completed',
                [
                    'section' => $this->sectionPayload(
                        $section,
                        $existingProgress->completed_at ?? $existingProgress->updated_at
                    ),
                    'course_progress' => $courseProgress,
                ]
            );
        }

        $courseSections = CourseSection::query()
            ->where('course_id', $courseId)
            ->orderBy('order')
            ->get();
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $courseSections->pluck('id')
        );
        $sectionState = $this->coursePresentation->sectionLockStatus(
            $courseSections,
            $completedSectionIds,
            (int) $user->id
        )->firstWhere('section_id', $section->id);

        if (($sectionState['is_locked'] ?? true) === true) {
            return $this->failure(
                409,
                'Complete the previous step before continuing',
                $sectionState['lock_reason'] ?? 'section_locked'
            );
        }

        $progress = DB::transaction(function () use (
            $user,
            $sectionId,
            $courseId
        ): StudentSectionProgress {
            User::query()->lockForUpdate()->findOrFail($user->id);

            $progress = StudentSectionProgress::firstOrNew([
                'user_id' => $user->id,
                'course_section_id' => $sectionId,
            ]);
            $progress->is_completed = true;
            $progress->completed_at ??= now();
            $progress->save();

            // The completion signal is part of the same commit as the last
            // section. Queue availability is irrelevant to this guarantee.
            $this->recordCourseCompletionIfEligible((int) $user->id, $courseId);

            return $progress;
        }, 3);

        $courseProgress = $this->coursePresentation->progressSummary(
            (int) $user->id,
            $courseId
        );
        return $this->success('Section marked as completed successfully', [
            'section' => $this->sectionPayload(
                $section,
                $progress->completed_at ?? $progress->updated_at
            ),
            'course_progress' => $courseProgress,
        ]);
    }

    /** @return array<string, mixed> */
    private function recordCourseCompletionIfEligible(int $userId, int $courseId): array
    {
        $progress = $this->coursePresentation->progressSummary($userId, $courseId);
        if ($progress['is_completed']) {
            $this->internalSignals->record(
                'course.completed',
                "user:{$userId}:course:{$courseId}",
                ['user_id' => $userId, 'course_id' => $courseId],
                'course_enrollment',
                "{$userId}:{$courseId}"
            );
        }

        return $progress;
    }

    public function canAccessSection(User $user, CourseSection $section): bool
    {
        $course = $section->relationLoaded('course')
            ? $section->course
            : Course::find($section->course_id);
        if (!$course || !$this->courseAccess->hasCourseAccess($user, $course)) {
            return false;
        }

        $sections = CourseSection::query()
            ->where('course_id', $section->course_id)
            ->get();
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        );

        $state = $this->coursePresentation->sectionLockStatus(
            $sections,
            $completedSectionIds,
            (int) $user->id
        )->firstWhere('section_id', $section->id);

        return (bool) ($state['can_access'] ?? false);
    }

    public function accessStates(User $user, Collection $sections): Collection
    {
        if ($sections->isEmpty()) {
            return collect();
        }

        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        );

        return $this->coursePresentation->sectionLockStatus(
            $sections,
            $completedSectionIds,
            (int) $user->id
        )->map(fn (array $state): array => $this->accessState(
            $state['section_id'],
            (bool) $state['can_access']
        ));
    }

    private function hasPassedQuiz(User $user, Course $course, CourseSection $section): bool
    {
        $sectionIds = $this->stagedAuthoring->equivalentEntityIds(
            CourseSection::class,
            (int) $section->id
        );
        $quizIds = $this->stagedAuthoring->equivalentEntityIds(
            \App\Models\ItemList::class,
            (int) $section->sectionable_id
        );

        return ExamAttempt::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where(function ($attempts) use ($sectionIds, $quizIds): void {
                $attempts->whereIn('section_id', $sectionIds)
                    ->orWhere(function ($legacyAttempts) use ($quizIds): void {
                        $legacyAttempts->whereNull('section_id')
                            ->whereIn('quiz_id', $quizIds);
                    });
            })
            ->where('status', ExamAttempt::STATUS_COMPLETED)
            ->where('is_passed', true)
            ->exists();
    }

    /** @return array{section_id:mixed,can_access:bool,is_locked:bool} */
    private function accessState(mixed $sectionId, bool $canAccess): array
    {
        return [
            'section_id' => $sectionId,
            'can_access' => $canAccess,
            'is_locked' => !$canAccess,
        ];
    }

    /** @return array<string, mixed> */
    private function sectionPayload(CourseSection $section, mixed $completedAt): array
    {
        return [
            'id' => $section->id,
            'title' => $section->title_ar ?? $section->title,
            'type' => $section->getSectionType(),
            'order' => $section->order,
            'is_completed' => true,
            'completed_at' => $completedAt,
        ];
    }

    /** @return array{success:true,status:200,message:string,data:mixed} */
    private function success(string $message, mixed $data): array
    {
        return [
            'success' => true,
            'status' => 200,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array{success:false,status:int,message:string,data:mixed,code?:string}
     */
    private function failure(
        int $status,
        string $message,
        ?string $code = null,
        mixed $data = null
    ): array {
        $result = [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
        if ($code !== null) {
            $result['code'] = $code;
        }

        return $result;
    }
}
