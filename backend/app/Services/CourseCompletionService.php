<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CourseCompleted;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CourseCompletionService
{
    public function __construct(
        private CourseReadCompatibilityService $courseReads,
        private CoursePresentationService $coursePresentation,
        private LearningEvidenceService $learningEvidence
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

        $existingProgress = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->where('course_section_id', $sectionId)
            ->first();

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
            return $this->success(
                'Section already completed',
                ['section' => $this->sectionPayload($section, $existingProgress->updated_at)]
            );
        }

        $courseSections = CourseSection::query()
            ->where('course_id', $courseId)
            ->orderBy('order')
            ->get();
        $completedSectionIds = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $courseSections->pluck('id'))
            ->where('is_completed', true)
            ->pluck('course_section_id');
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

        $progress = DB::transaction(function () use ($user, $sectionId): StudentSectionProgress {
            User::query()->lockForUpdate()->findOrFail($user->id);

            $progress = StudentSectionProgress::firstOrNew([
                'user_id' => $user->id,
                'course_section_id' => $sectionId,
            ]);
            $progress->is_completed = true;
            $progress->save();

            return $progress;
        });

        $courseProgress = $this->coursePresentation->progressSummary(
            (int) $user->id,
            $courseId
        );
        if ($courseProgress['is_completed']) {
            event(new CourseCompleted($user, $course));
        }

        return $this->success('Section marked as completed successfully', [
            'section' => $this->sectionPayload($section, $progress->updated_at),
            'course_progress' => $courseProgress,
        ]);
    }

    public function canAccessSection(User $user, CourseSection $section): bool
    {
        $previousSection = CourseSection::query()
            ->where('course_id', $section->course_id)
            ->where('order', '<', $section->order)
            ->orderByDesc('order')
            ->first();
        if (!$previousSection) {
            return true;
        }

        $previousCompleted = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->where('course_section_id', $previousSection->id)
            ->where('is_completed', true)
            ->exists();
        if (!$previousCompleted) {
            return false;
        }

        return !(
            $section->module_id
            && $previousSection->module_id !== $section->module_id
            && !$this->hasPassedPreviousModuleProject((int) $user->id, $section)
        );
    }

    public function accessStates(User $user, Collection $sections): Collection
    {
        if ($sections->isEmpty()) {
            return collect();
        }

        $completedSectionIds = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $sections->pluck('id'))
            ->where('is_completed', true)
            ->pluck('course_section_id');

        return $sections->map(function ($section) use ($completedSectionIds, $sections): array {
            if ($section->order == 1) {
                return $this->accessState($section->id, true);
            }

            $previousSection = $sections
                ->where('order', '<', $section->order)
                ->sortByDesc('order')
                ->first();
            $canAccess = !$previousSection
                || $completedSectionIds->contains($previousSection->id);

            return $this->accessState($section->id, $canAccess);
        });
    }

    private function hasPassedQuiz(User $user, Course $course, CourseSection $section): bool
    {
        return ExamAttempt::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where(function ($attempts) use ($section): void {
                $attempts->where('section_id', $section->id)
                    ->orWhere('quiz_id', $section->sectionable_id);
            })
            ->where('status', ExamAttempt::STATUS_COMPLETED)
            ->where('is_passed', true)
            ->exists();
    }

    private function hasPassedPreviousModuleProject(
        int $userId,
        CourseSection $currentSection
    ): bool {
        if (!$currentSection->module_id) {
            return true;
        }

        $currentModule = $currentSection->module;
        if (!$currentModule || $currentModule->order <= 1) {
            return true;
        }

        $previousModule = CourseModule::query()
            ->where('course_id', $currentSection->course_id)
            ->where('order', '<', $currentModule->order)
            ->orderByDesc('order')
            ->first();
        if (!$previousModule) {
            return true;
        }

        $projectSection = CourseSection::query()
            ->where('module_id', $previousModule->id)
            ->where('section_type', 'project')
            ->first();
        if (!$projectSection || !$projectSection->project) {
            return true;
        }

        return UserProjectEvaluation::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectSection->project->id)
            ->where('passed', true)
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
