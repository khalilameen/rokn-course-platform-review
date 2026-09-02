<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class CourseReadCompatibilityService
{
    public function __construct(
        private CourseDurationService $duration,
        private CourseChatAccessService $courseAccess,
        private CourseCatalogueQueryService $catalogue
    ) {}

    /**
     * @return array{course: Course, is_enrolled: bool}
     */
    public function legacyCourse(Course $course, ?User $user): array
    {
        $isEnrolled = $course->isPublishedForLearning()
            && $user !== null
            && $this->courseAccess->hasLearningAccess((int) $user->id, (int) $course->id);

        if (!$isEnrolled && !$this->catalogue->isPubliclyDiscoverable((int) $course->id)) {
            abort(404);
        }

        $course->load([
            'photo',
            'grade',
            'coursePath',
            'classifications',
            'teacher' => fn ($teacher) => $teacher
                ->where('active', true)
                ->whereIn('role', ['teacher', 'admin']),
            'teacher.photo',
            'teachers' => fn ($teachers) => $teachers
                ->where('users.active', true)
                ->orderBy('users.id'),
            'teachers.photo',
            'sections.sectionable',
            'modules.attachments',
            'modules.sections.sectionable',
            'activePdfs',
            'accessPlans' => fn ($plans) => $plans->where('is_active', true),
        ]);
        $this->loadLessonMediaState($course);
        $this->duration->attach($course);

        return ['course' => $course, 'is_enrolled' => $isEnrolled];
    }

    /**
     * @return array{course: Course, has_access: bool, access_type: string, unavailable: bool, entitlement: array<string,mixed>, enrollment: CourseEnrollment|null}
     */
    public function detailedCourse(int $courseId, ?User $user): array
    {
        $course = $this->loadDetailedCourse($courseId);
        $resolution = $user
            ? $this->courseAccess->resolveEntitlement((int) $user->id, $courseId)
            : [
                'entitlement' => ['has_learning_access' => false, 'access_type' => 'none'],
                'enrollment' => null,
            ];
        $access = $resolution['entitlement'];

        $publishedForLearning = $course->isPublishedForLearning();
        $hasAccess = $publishedForLearning && (bool) $access['has_learning_access'];
        $enrollment = $hasAccess ? $resolution['enrollment'] : null;

        return [
            'course' => $course,
            // An old enrollment must never turn a draft back into live
            // content. Announced courses still use the public short resource.
            'has_access' => $hasAccess,
            'access_type' => $access['access_type'],
            'entitlement' => $access,
            'enrollment' => $enrollment,
            'unavailable' => !$hasAccess
                && !$this->catalogue->isPubliclyDiscoverable((int) $course->id),
        ];
    }

    /** Draft override for the authenticated dashboard only; presentation is shared. */
    public function detailedCourseForAdminPreview(int $courseId): Course
    {
        return $this->loadDetailedCourse($courseId);
    }

    private function loadDetailedCourse(int $courseId): Course
    {
        $course = $this->catalogue->withPublicPlanFacts(Course::query())->with([
            'photo',
            'grade',
            'coursePath',
            'classifications',
            'teacher' => fn ($teacher) => $teacher
                ->where('active', true)
                ->whereIn('role', ['teacher', 'admin']),
            'teacher.photo',
            'teachers' => fn ($teachers) => $teachers
                ->where('users.active', true)
                ->orderBy('users.id'),
            'teachers.photo',
            'activePdfs',
            'accessPlans' => fn ($plans) => $plans->where('is_active', true),
            'sections.sectionable',
            'modules' => function ($modules): void {
                $modules->with([
                    'sections' => function ($sections): void {
                        $sections->with(['sectionable', 'attachments'])->orderBy('order');
                    },
                    'attachments',
                ])->orderBy('order');
            },
        ])->findOrFail($courseId);
        $course->loadCount(['ratings', 'activeEnrollments']);
        $course->loadAvg('ratings', 'rating');
        $this->loadLessonMediaState($course);
        $this->duration->attach($course);

        return $course;
    }

    /**
     * @return array{course: Course|null, enrollment: CourseEnrollment|null, access_type: string}
     */
    public function progressCourse(int $userId, int $courseId): array
    {
        $resolution = $this->courseAccess->resolveEntitlement($userId, $courseId);
        $entitlement = $resolution['entitlement'];
        $enrollment = $entitlement['has_learning_access']
            ? $resolution['enrollment']
            : null;

        if (!$enrollment) {
            return [
                'course' => null,
                'enrollment' => null,
                'access_type' => 'none',
            ];
        }

        $course = Course::with([
            'sections' => fn ($sections) => $sections->orderBy('order'),
        ])->where('is_coming_soon', false)->findOrFail($courseId);

        if (!$course->isPublishedForLearning()) {
            return [
                'course' => null,
                'enrollment' => null,
                'access_type' => 'none',
            ];
        }

        return [
            'course' => $course,
            'enrollment' => $enrollment,
            'access_type' => (string) $entitlement['access_type'],
        ];
    }

    public function hasLearningAccess(int $userId, int $courseId): bool
    {
        $course = Course::query()->find($courseId);

        return $course !== null
            && $course->isPublishedForLearning()
            && $this->courseAccess->hasLearningAccess($userId, $courseId);
    }

    private function loadLessonMediaState(Course $course): void
    {
        $course->sections->loadMorph('sectionable', [
            Lesson::class => ['mediaState'],
        ]);
        $moduleSections = new EloquentCollection(
            $course->modules
                ->flatMap(fn ($module) => $module->sections)
                ->all()
        );
        $moduleSections->loadMorph('sectionable', [
            Lesson::class => ['mediaState'],
        ]);
    }
}
