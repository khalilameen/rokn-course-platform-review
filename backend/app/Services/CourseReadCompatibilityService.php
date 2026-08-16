<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\User;

final readonly class CourseReadCompatibilityService
{
    public function __construct(private CourseDurationService $duration)
    {
    }

    /**
     * @return array{course: Course, is_enrolled: bool}
     */
    public function legacyCourse(Course $course, ?User $user): array
    {
        $isEnrolled = $user !== null
            && $this->activeDirectEnrollment((int) $user->id, (int) $course->id) !== null;

        if (!$isEnrolled && (
            ((bool) $course->is_coming_soon && !(bool) $course->is_catalog_visible)
            || (!(bool) $course->is_coming_soon && !$course->sections()->exists())
        )) {
            abort(404);
        }

        $course->load([
            'photo',
            'grade',
            'coursePath',
            'classifications',
            'teachers.photo',
            'sections.sectionable',
            'modules.attachments',
            'modules.sections.sectionable',
        ]);
        $this->duration->attach($course);

        return ['course' => $course, 'is_enrolled' => $isEnrolled];
    }

    /**
     * @return array{course: Course, has_access: bool, access_type: string, unavailable: bool}
     */
    public function detailedCourse(int $courseId, ?User $user): array
    {
        $access = $user
            ? $this->detailAccess((int) $user->id, $courseId)
            : ['has_access' => false, 'access_type' => 'none'];

        $course = Course::with([
            'photo',
            'grade',
            'coursePath',
            'classifications',
            'teachers.photo',
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
        $this->duration->attach($course);

        return [
            'course' => $course,
            'has_access' => $access['has_access'],
            'access_type' => $access['access_type'],
            'unavailable' => !$access['has_access']
                && ((bool) $course->is_coming_soon || !$course->sections()->exists()),
        ];
    }

    /**
     * @return array{course: Course|null, enrollment: CourseEnrollment|null, access_type: string}
     */
    public function progressCourse(int $userId, int $courseId): array
    {
        $enrollment = CourseEnrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();
        $accessType = 'direct';

        if (!$enrollment) {
            $parent = $this->parentAccess($userId, $courseId);
            $enrollment = $parent['enrollment'];
            $accessType = 'parent';
        }

        if (!$enrollment) {
            return [
                'course' => null,
                'enrollment' => null,
                'access_type' => $accessType,
            ];
        }

        $course = Course::with([
            'sections' => fn ($sections) => $sections->orderBy('order'),
        ])->findOrFail($courseId);

        return [
            'course' => $course,
            'enrollment' => $enrollment,
            'access_type' => $accessType,
        ];
    }

    public function hasLearningAccess(int $userId, int $courseId): bool
    {
        if ($this->activeDirectEnrollment($userId, $courseId) !== null) {
            return true;
        }

        return $this->parentAccess($userId, $courseId)['has_access'];
    }

    private function activeDirectEnrollment(int $userId, int $courseId): ?CourseEnrollment
    {
        $enrollment = CourseEnrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();

        return $enrollment && $enrollment->isActive() ? $enrollment : null;
    }

    /**
     * @return array{has_access: bool, access_type: string}
     */
    private function detailAccess(int $userId, int $courseId): array
    {
        if ($this->activeDirectEnrollment($userId, $courseId) !== null) {
            return ['has_access' => true, 'access_type' => 'direct'];
        }

        $parent = $this->parentAccess($userId, $courseId);

        return [
            'has_access' => $parent['has_access'],
            'access_type' => $parent['has_access'] ? 'parent' : 'none',
        ];
    }

    /**
     * @return array{has_access: bool, enrollment: CourseEnrollment|null}
     */
    private function parentAccess(int $userId, int $courseId): array
    {
        $parentCourseIds = CourseSection::query()
            ->where('sectionable_type', Course::class)
            ->where('sectionable_id', $courseId)
            ->pluck('course_id')
            ->all();

        if ($parentCourseIds === []) {
            return ['has_access' => false, 'enrollment' => null];
        }

        $enrollment = CourseEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('course_id', $parentCourseIds)
            ->where('is_active', true)
            ->where(function ($enrollments): void {
                $enrollments->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$enrollment || !$enrollment->isActive()) {
            return ['has_access' => false, 'enrollment' => null];
        }

        return ['has_access' => true, 'enrollment' => $enrollment];
    }
}
