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
        private CurriculumCompletionService $curriculumCompletion
    ) {
    }

    /** @return array{included:bool,available:bool,reason:string} */
    public function for(User $user, Course $course): array
    {
        $earnedEnrollment = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->where(function ($active): void {
                $active->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
        $earnedRevision = $earnedEnrollment
            ? $this->curriculumCompletion->earnedRevision($earnedEnrollment)
            : null;
        $included = $earnedRevision !== null
            ? $this->courseAccess->enrollmentHasCertificateAccess($earnedEnrollment)
            : $this->courseAccess->hasCertificateAccess((int) $user->id, (int) $course->id);
        if (!$included) return ['included' => false, 'available' => false, 'reason' => 'upgrade_required'];

        $enrollment = $earnedRevision !== null
            ? $earnedEnrollment
            : $this->courseAccess->activeEnrollmentFor((int) $user->id, (int) $course->id);
        if (!$enrollment) return ['included' => true, 'available' => false, 'reason' => 'entitlement_inactive'];
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
        $completed = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $sections->pluck('id'))
            ->where('is_completed', true)
            ->distinct('course_section_id')
            ->count('course_section_id');
        if ($completed !== $sections->count()) {
            return ['included' => true, 'available' => false, 'reason' => 'course_incomplete'];
        }

        $lessonSections = $sections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'lesson'
        );
        if ($lessonSections->isNotEmpty()) {
            $lessons = Lesson::query()->whereIn('id', $lessonSections->pluck('sectionable_id'))->get()->keyBy('id');
            $evidence = LessonWatchEvidence::query()
                ->where('user_id', $user->id)
                ->whereIn('course_section_id', $lessonSections->pluck('id'))
                ->get()->keyBy('course_section_id');
            foreach ($lessonSections as $section) {
                $lesson = $lessons->get($section->sectionable_id);
                $row = $evidence->get($section->id);
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
            $passed = ExamAttempt::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', ExamAttempt::STATUS_COMPLETED)
                ->where('is_passed', true)
                ->where(function ($attempts) use ($quizSections): void {
                    $attempts->whereIn('section_id', $quizSections->pluck('id'))
                        ->orWhere(function ($legacy) use ($quizSections): void {
                            $legacy->whereNull('section_id')->whereIn('quiz_id', $quizSections->pluck('sectionable_id'));
                        });
                })->get(['section_id', 'quiz_id']);
            foreach ($quizSections as $section) {
                if (!$passed->contains(fn (ExamAttempt $attempt): bool =>
                    (int) $attempt->section_id === (int) $section->id
                    || ($attempt->section_id === null && (int) $attempt->quiz_id === (int) $section->sectionable_id)
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
                $passedGraduationProjects = UserProjectEvaluation::query()
                    ->where('user_id', $user->id)
                    ->whereIn('project_id', $graduationProjectIds)
                    ->where('passed', true)
                    ->distinct('project_id')
                    ->count('project_id');
                if ($passedGraduationProjects !== $graduationProjectIds->count()) {
                    return ['included' => true, 'available' => false, 'reason' => 'graduation_project_incomplete'];
                }
            }
        }

        return ['included' => true, 'available' => true, 'reason' => 'ready'];
    }
}
