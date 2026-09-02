<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\Order;
use App\Models\Project;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;

final readonly class CertificateEligibilityService
{
    public function __construct(
        private CourseChatAccessService $courseAccess,
        private CourseSectionSequenceService $sectionSequence,
        private LearningEvidenceService $learningEvidence,
        private CurriculumCompletionService $curriculumCompletion,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    /** @return array{included:bool,available:bool,reason:string} */
    public function for(User $user, Course $course): array
    {
        $enrollment = $this->enrollmentFor($user, $course);
        $earnedRevision = $enrollment
            ? $this->curriculumCompletion->earnedRevision($enrollment)
            : null;
        if (!$enrollment) {
            return ['included' => false, 'available' => false, 'reason' => 'upgrade_required'];
        }

        $included = $this->courseAccess->enrollmentHasCertificateAccess($enrollment);
        if (!$included) return ['included' => false, 'available' => false, 'reason' => 'upgrade_required'];
        if ($earnedRevision === null && !$enrollment->isActive()) {
            return ['included' => true, 'available' => false, 'reason' => 'entitlement_inactive'];
        }
        if ($enrollment->order_id && Order::query()
            ->whereKey($enrollment->order_id)
            ->where('user_id', $user->id)
            ->whereIn('financial_status', [Order::FINANCIAL_PARTIALLY_RECOVERED, Order::FINANCIAL_REVIEW_REQUIRED])
            ->where('unrecovered_coins', '>', 0)
            ->exists()) {
            return ['included' => true, 'available' => false, 'reason' => 'financial_review'];
        }

        // Completion is an earned fact about a published revision. Moving the
        // course to draft to author its next revision, adding sections, or
        // changing its hierarchy must not revoke that fact.
        if ($earnedRevision !== null) {
            return ['included' => true, 'available' => true, 'reason' => 'ready'];
        }
        if (!$course->isPublishedForLearning() || $course->isNestedCourse()) {
            return ['included' => true, 'available' => false, 'reason' => 'course_unavailable'];
        }

        $sections = $this->sectionSequence->learning(
            CourseSection::query()
                ->where('course_id', $course->id)
                ->get(['id', 'course_id', 'section_type', 'sectionable_type', 'sectionable_id', 'module_id', 'order'])
        );
        if ($sections->isEmpty()) return ['included' => true, 'available' => false, 'reason' => 'course_incomplete'];
        $completed = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        )->count();
        if ($completed !== $sections->count()) {
            return ['included' => true, 'available' => false, 'reason' => 'course_incomplete'];
        }

        $lessonSections = $sections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'lesson'
        );
        if ($lessonSections->isNotEmpty()) {
            $lessons = Lesson::query()->whereIn('id', $lessonSections->pluck('sectionable_id'))->get()->keyBy('id');
            $evidence = $this->revisionReads->lessonEvidenceMap(
                (int) $user->id,
                $lessons->keys()
            );
            foreach ($lessonSections as $section) {
                $lesson = $lessons->get($section->sectionable_id);
                $row = $lesson
                    ? $evidence->get((int) $lesson->id)
                    : null;
                $required = $lesson && $row
                    ? $this->learningEvidence->requiredSeconds($lesson, $row->duration_seconds)
                    : null;
                if ($required === null || (int) $row->verified_seconds < $required) {
                    return ['included' => true, 'available' => false, 'reason' => 'learning_evidence_incomplete'];
                }
            }
        }

        $quizSections = $sections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'quiz'
        );
        if ($quizSections->isNotEmpty()) {
            $sectionAliases = $quizSections->mapWithKeys(fn (CourseSection $section): array => [
                (int) $section->id => $this->stagedAuthoring->equivalentEntityIds(
                    CourseSection::class,
                    (int) $section->id
                ),
            ]);
            $quizAliases = $quizSections->mapWithKeys(fn (CourseSection $section): array => [
                (int) $section->id => $this->stagedAuthoring->equivalentEntityIds(
                    \App\Models\ItemList::class,
                    (int) $section->sectionable_id
                ),
            ]);
            $passed = ExamAttempt::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', ExamAttempt::STATUS_COMPLETED)
                ->where('is_passed', true)
                ->whereIn('quiz_id', $quizAliases->flatten()->unique()->values())
                ->get(['section_id', 'quiz_id']);
            foreach ($quizSections as $section) {
                if (!$passed->contains(fn (ExamAttempt $attempt): bool =>
                    in_array((int) $attempt->section_id, $sectionAliases->get((int) $section->id, []), true)
                    || ($attempt->section_id === null && in_array(
                        (int) $attempt->quiz_id,
                        $quizAliases->get((int) $section->id, []),
                        true
                    ))
                )) {
                    return ['included' => true, 'available' => false, 'reason' => 'quiz_incomplete'];
                }
            }
        }

        $graduationProjectIds = $sections
            ->filter(fn (CourseSection $section): bool => $section->getSectionType() === 'project')
            ->pluck('sectionable_id');
        if ($graduationProjectIds->isNotEmpty()) {
            $graduationProjectIds = Project::query()
                ->whereIn('id', $graduationProjectIds)
                ->where('is_graduation_project', true)
                ->pluck('id');
            if ($graduationProjectIds->isNotEmpty()) {
                $passedGraduationProjects = $this->revisionReads->passedProjectIds(
                    (int) $user->id,
                    $graduationProjectIds
                )->count();
                if ($passedGraduationProjects !== $graduationProjectIds->count()) {
                    return ['included' => true, 'available' => false, 'reason' => 'graduation_project_incomplete'];
                }
            }
        }

        return ['included' => true, 'available' => true, 'reason' => 'ready'];
    }

    /**
     * Prefer the enrollment carrying the immutable earned revision. Access
     * expiry may close lessons, but it cannot erase a completion already won.
     */
    public function enrollmentFor(User $user, Course $course): ?CourseEnrollment
    {
        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest('id')
            ->get();

        return $enrollments->first(fn (CourseEnrollment $candidate): bool =>
            $this->curriculumCompletion->earnedRevision($candidate) !== null
        ) ?? $enrollments->first(fn (CourseEnrollment $candidate): bool => $candidate->isActive());
    }
}
