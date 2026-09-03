<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\BaseCourseResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class CoursePresentationService
{
    public function __construct(
        private CourseChatAccessService $chatAccess,
        private CourseSectionSequenceService $sectionSequence,
        private CertificateEligibilityService $certificateEligibility,
        private CourseRevisionLearnerReadService $revisionReads
    )
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
        bool $hasAccess,
        ?array $resolvedEntitlement = null,
        ?CourseEnrollment $resolvedEnrollment = null
    ): BaseCourseResource {
        $completedSectionIds = collect();
        if ($user && $hasAccess) {
            $completedSectionIds = $this->revisionReads->completedSectionIds(
                (int) $user->id,
                $course->sections->pluck('id')
            );
            $resource = (new CourseResource($course))->withLearningContext(
                $completedSectionIds,
                $resolvedEntitlement,
                $resolvedEnrollment
            );
        } else {
            $resource = new BaseCourseResource($course);
        }

        $entitlement = $user
            ? ($resolvedEntitlement
                ?? $this->chatAccess->entitlementFor((int) $user->id, (int) $course->id))
            : [
                'access_type' => 'none',
                'chat_available' => false,
                'certificate_available' => false,
            ];
        $certificateIncludedByPlan = (bool) $entitlement['certificate_available'];
        // CertificateEligibilityService is the single owner of the earned
        // completion contract. In particular, an already-earned published
        // revision remains eligible after a moderator publishes extra steps;
        // pre-gating on the current map would hide that certificate here while
        // the issue endpoint correctly continued to allow it.
        $certificateStatus = $user && $hasAccess && $certificateIncludedByPlan
            ? $this->certificateEligibility->for($user, $course)
            : ['included' => $certificateIncludedByPlan, 'available' => false];
        $certificateIncluded = (bool) $certificateStatus['included'];
        $certificateAvailable = (bool) $certificateStatus['available'];

        return $resource->withEntitlement(
            (string) $entitlement['access_type'],
            (bool) $entitlement['chat_available'],
            $certificateIncluded,
            $certificateAvailable
        );
    }

    /**
     * Build the exact entitled learner resource for a dashboard-only preview.
     * No enrollment, progress row or publication flag is written.
     *
     * @param array<string, mixed> $planContract
     */
    public function dashboardPreview(
        Course $course,
        User $actor,
        array $planContract,
        string $accessType
    ): CourseResource {
        $entitlement = $planContract + [
            'has_learning_access' => true,
            'access_type' => $accessType,
            'chat_available' => (bool) ($planContract['chat_enabled'] ?? false),
            'certificate_available' => (bool) ($planContract['certificate_enabled'] ?? false),
            'project_feedback_level' => (string) (
                $planContract['project_feedback_level'] ?? 'pass_only'
            ),
        ];

        return (new CourseResource($course))
            ->withLearningContext(collect(), $entitlement, null)
            ->withDashboardPreviewContext($actor, $planContract)
            ->withEntitlement(
                $accessType,
                (bool) $entitlement['chat_available'],
                (bool) $entitlement['certificate_available'],
                false
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
        $learningSections = $this->sectionSequence->learning($course->sections);
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            $userId,
            $learningSections->pluck('id')
        );
        $totalSections = $learningSections->count();
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
                $learningSections,
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
        try {
            $settings = Cache::remember(
                'learning:sequence-settings:v2',
                30,
                fn () => Setting::query()->first()
            );
        } catch (Throwable) {
            $settings = Setting::query()->first();
        }
        $enforceSectionOrder = $settings
            ? (bool) $settings->enforce_course_section_order
            : true;
        $moduleProjectStatus = [];
        $orderedSections = $this->sectionSequence->learning($sections);
        $previousSections = [];
        $previousSection = null;
        foreach ($orderedSections as $orderedSection) {
            $previousSections[(int) $orderedSection->id] = $previousSection;
            $previousSection = $orderedSection;
        }

        if ($userId) {
            $projectsByModule = $sections
                ->filter(fn ($section): bool => $section->module_id && $section->getSectionType() === 'project')
                ->groupBy('module_id');
            $projectIds = $projectsByModule->flatten(1)->pluck('sectionable_id')->filter();
            $passedProjectIds = $projectIds->isEmpty()
                ? collect()
                : $this->revisionReads->passedProjectIds($userId, $projectIds);

            foreach ($sections->pluck('module_id')->filter()->unique() as $moduleId) {
                $projectSections = $projectsByModule->get($moduleId, collect());
                $moduleProjectStatus[$moduleId] = $projectSections->isEmpty()
                    || $projectSections->every(
                        fn ($projectSection): bool => $passedProjectIds->contains($projectSection->sectionable_id)
                    );
            }
        }

        return $orderedSections->map(function ($section) use (
            $completedSectionIds,
            $enforceSectionOrder,
            $moduleProjectStatus,
            $previousSections,
            $userId
        ): array {
            $isCompleted = $completedSectionIds->contains($section->id);
            $isLocked = false;
            $lockReason = null;

            if ($enforceSectionOrder) {
                $previousSection = $previousSections[(int) $section->id] ?? null;

                if ($previousSection) {
                    if (!$completedSectionIds->contains($previousSection->id)) {
                        $isLocked = true;
                        // A project is a deliberate course gate, not just an
                        // unfinished neighbouring section. Preserve that
                        // distinction for the client so it can show the
                        // project action instead of a generic locked row.
                        $lockReason = $userId
                            && $previousSection->getSectionType() === 'project'
                            ? 'module_project_not_passed'
                            : 'previous_section_incomplete';
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
        $learningSections = $this->sectionSequence->learning($course->sections);
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            $userId,
            $learningSections->pluck('id')
        );
        $totalSections = $learningSections->count();
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
