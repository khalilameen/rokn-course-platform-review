<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\BaseCourseResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Setting;
use App\Models\StudentSectionProgress;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class CoursePresentationService
{
    public function __construct(private CourseChatAccessService $chatAccess)
    {
    }

    public function catalogueCollection(
        LengthAwarePaginator $courses
    ): AnonymousResourceCollection {
        return BaseCourseResource::collection($courses);
    }

    /**
     * @return array{courses: array<int, mixed>, pagination: array<string, int|null>}
     */
    public function mobileCataloguePayload(LengthAwarePaginator $courses): array
    {
        // Resource mapping must not mutate a paginator retained by an in-memory
        // cache store or a long-lived worker. Otherwise the next request sees
        // resources where the cached contract promises Course models.
        $presentedCourses = clone $courses;
        $presentedCourses->setCollection(
            $courses->getCollection()->map(
                fn (Course $course): BaseCourseResource => new BaseCourseResource($course)
            )
        );

        return [
            'courses' => $presentedCourses->items(),
            'pagination' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
                'from' => $courses->firstItem(),
                'to' => $courses->lastItem(),
            ],
        ];
    }

    public function legacyCourse(Course $course, bool $isEnrolled): BaseCourseResource
    {
        return $isEnrolled
            ? new CourseResource($course)
            : new BaseCourseResource($course);
    }

    public function detailedCourse(
        Course $course,
        ?User $user,
        bool $hasAccess
    ): BaseCourseResource {
        if ($user && $hasAccess) {
            $completedSectionIds = StudentSectionProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('course_section_id', $course->sections->pluck('id'))
                ->where('is_completed', true)
                ->pluck('course_section_id');
            $resource = new CourseResource($course, $completedSectionIds);
        } else {
            $resource = new BaseCourseResource($course);
        }

        $entitlement = $user
            ? $this->chatAccess->entitlementFor((int) $user->id, (int) $course->id)
            : [
                'access_type' => 'none',
                'chat_available' => false,
                'certificate_available' => false,
            ];

        return $resource->withEntitlement(
            (string) $entitlement['access_type'],
            (bool) $entitlement['chat_available'],
            (bool) $entitlement['certificate_available']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function progressPayload(
        Course $course,
        CourseEnrollment $enrollment,
        string $accessType,
        int $userId
    ): array {
        $completedSectionIds = StudentSectionProgress::query()
            ->where('user_id', $userId)
            ->whereIn('course_section_id', $course->sections->pluck('id'))
            ->where('is_completed', true)
            ->pluck('course_section_id');
        $totalSections = $course->sections->count();
        $completedSections = $completedSectionIds->count();
        $progressPercentage = $totalSections > 0
            ? round(($completedSections / $totalSections) * 100, 2)
            : 0;

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->name_ar,
                'title_en' => $course->name_en,
                'image' => $course->image,
            ],
            'enrollment' => [
                'id' => $enrollment->id,
                'enrolled_at' => $enrollment->enrolled_at,
                'expires_at' => $enrollment->expires_at,
                'is_active' => $enrollment->isActive(),
                'access_type' => $accessType,
            ],
            'progress' => [
                'total_sections' => $totalSections,
                'completed_sections' => $completedSections,
                'progress_percentage' => $progressPercentage,
                'is_completed' => $totalSections > 0 && $completedSections === $totalSections,
            ],
            'sections' => $this->sectionLockStatus(
                $course->sections,
                $completedSectionIds,
                $userId
            ),
        ];
    }

    public function sectionLockStatus(
        Collection $sections,
        Collection $completedSectionIds,
        ?int $userId = null
    ): Collection {
        $settings = Setting::first();
        $enforceSectionOrder = $settings
            ? (bool) $settings->enforce_course_section_order
            : true;
        $moduleProjectStatus = [];

        if ($userId) {
            foreach ($sections->pluck('module_id')->filter()->unique() as $moduleId) {
                $module = CourseModule::find($moduleId);
                if ($module) {
                    $moduleProjectStatus[$moduleId] = $module->userPassedProject($userId);
                }
            }
        }

        return $sections->map(function ($section) use (
            $completedSectionIds,
            $enforceSectionOrder,
            $moduleProjectStatus,
            $sections,
            $userId
        ): array {
            $isCompleted = $completedSectionIds->contains($section->id);
            $isLocked = false;
            $lockReason = null;

            if ($enforceSectionOrder && $section->order > 1) {
                $previousSection = $sections
                    ->where('order', '<', $section->order)
                    ->sortByDesc('order')
                    ->first();

                if ($previousSection) {
                    if (!$completedSectionIds->contains($previousSection->id)) {
                        $isLocked = true;
                        $lockReason = 'previous_section_incomplete';
                    }

                    if (
                        !$isLocked
                        && $userId
                        && $section->module_id
                        && $previousSection->module_id !== $section->module_id
                    ) {
                        $previousModuleId = $previousSection->module_id;
                        if (
                            $previousModuleId
                            && isset($moduleProjectStatus[$previousModuleId])
                            && !$moduleProjectStatus[$previousModuleId]
                        ) {
                            $isLocked = true;
                            $lockReason = 'module_project_not_passed';
                        }
                    }
                }
            }

            return [
                'section_id' => $section->id,
                'title' => $section->title_ar ?? $section->title,
                'type' => $section->getSectionType(),
                'order' => $section->order,
                'module_id' => $section->module_id,
                'is_completed' => $isCompleted,
                'is_locked' => $isLocked,
                'lock_reason' => $lockReason,
                'can_access' => !$isLocked,
            ];
        });
    }

    /**
     * @return array<string, int|float|bool>
     */
    public function progressSummary(int $userId, int $courseId): array
    {
        $course = Course::with([
            'sections' => fn ($sections) => $sections->orderBy('order'),
        ])->findOrFail($courseId);
        $completedSectionIds = StudentSectionProgress::query()
            ->where('user_id', $userId)
            ->whereIn('course_section_id', $course->sections->pluck('id'))
            ->where('is_completed', true)
            ->pluck('course_section_id');
        $totalSections = $course->sections->count();
        $completedSections = $completedSectionIds->count();

        return [
            'total_sections' => $totalSections,
            'completed_sections' => $completedSections,
            'progress_percentage' => $totalSections > 0
                ? round(($completedSections / $totalSections) * 100, 2)
                : 0,
            'is_completed' => $totalSections > 0 && $completedSections === $totalSections,
        ];
    }
}
