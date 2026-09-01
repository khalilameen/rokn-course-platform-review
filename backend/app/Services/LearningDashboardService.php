<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\WatchingLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class LearningDashboardService
{
    private const MAX_ACTIVE_COURSES = 100;

    public function __construct(
        private CourseSectionSequenceService $sectionSequence,
        private CourseChatAccessService $courseAccess,
        private CertificateEligibilityService $certificateEligibility,
        private LatestWatchResumeService $latestResume
    ) {
    }

    /**
     * @return array{items: mixed}
     */
    public function forUser(User $user): array
    {
        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function (Builder $active): void {
                $active->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            // Filter before applying the bounded window. Otherwise an
            // unpublished or nested enrollment can consume one of the first
            // 101 rows, hide an older valid course and make has_more false.
            ->whereHas('course', static function (Builder $courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereNull('parent_id')
                    ->whereHas('sections')
                    ->whereDoesntHave('courseSection');
            })
            ->with([
                'course.photo',
                'course.classifications',
            ])
            ->latest('access_granted_at')
            ->latest('id')
            ->limit(self::MAX_ACTIVE_COURSES + 1)
            ->get();
        $hasMoreCourses = $enrollments->count() > self::MAX_ACTIVE_COURSES;
        $enrollments = $enrollments->take(self::MAX_ACTIVE_COURSES)->values();

        $courseIds = $enrollments
            ->pluck('course_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $sections = CourseSection::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('course_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get([
                'id', 'course_id', 'module_id', 'title', 'title_ar', 'title_en',
                'section_type', 'sectionable_type', 'sectionable_id', 'order',
            ]);
        $sectionsByCourse = $sections
            ->groupBy('course_id')
            ->map(fn ($courseSections) => $this->sectionSequence->learning($courseSections));
        $sections = $sectionsByCourse->flatten(1);
        $sectionCourse = $sections->pluck('course_id', 'id');
        $totalByCourse = $sectionsByCourse->map->count();
        $progressRows = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $sectionCourse->keys())
            ->get(['course_section_id', 'is_completed', 'completed_at', 'updated_at']);
        $completedSectionIds = $progressRows
            ->where('is_completed', true)
            ->pluck('course_section_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $completedByCourse = $progressRows
            ->where('is_completed', true)
            ->unique('course_section_id')
            ->map(fn ($progress) => $sectionCourse->get($progress->course_section_id))
            ->filter()
            ->countBy();
        $progressActivityByCourse = $progressRows
            ->groupBy(fn ($progress) => $sectionCourse->get($progress->course_section_id))
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => $row->completed_at ?? $row->updated_at)
                ->filter()
                ->max());

        $resumeByCourse = collect();
        if ((bool) $user->watch_history_enabled && $courseIds->isNotEmpty()) {
            $resumeByCourse = $this->latestResume->forUser(
                (int) $user->id,
                $courseIds,
                [
                    'lesson:id,list_id,title,title_ar,title_en,thumbnail_path',
                    'courseSection:id,course_id,sectionable_type,sectionable_id,order',
                ]
            )->filter(function (WatchingLog $log): bool {
                    return $log->lesson !== null
                        && $log->courseSection !== null
                        && (int) $log->lesson->list_id === (int) $log->course_id
                        && (int) $log->courseSection->course_id === (int) $log->course_id
                        && (int) $log->courseSection->sectionable_id === (int) $log->lesson_id;
                })
                ->keyBy(fn (WatchingLog $log): int => (int) $log->course_id);
        }

        $items = $enrollments->map(function (CourseEnrollment $enrollment) use (
            $totalByCourse,
            $completedByCourse,
            $progressActivityByCourse,
            $resumeByCourse,
            $sectionsByCourse,
            $completedSectionIds,
            $user
        ): array {
            $course = $enrollment->course;
            $courseId = (int) $course->id;
            $total = (int) $totalByCourse->get($courseId, 0);
            $completed = min($total, (int) $completedByCourse->get($courseId, 0));
            $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;
            $entitlement = $this->courseAccess->entitlementFor((int) $user->id, $courseId);
            $certificateStatus = (bool) $entitlement['certificate_available']
                && $total > 0
                && $completed === $total
                ? $this->certificateEligibility->for($user, $course)
                : [
                    'included' => (bool) $entitlement['certificate_available'],
                    'available' => false,
                ];

            /** @var WatchingLog|null $resumeLog */
            $resumeLog = $resumeByCourse->get($courseId);
            $resume = ['available' => false];
            $watchActivity = null;
            if ($resumeLog) {
                $duration = (int) ($resumeLog->duration_seconds ?? 0);
                $position = max(0, (int) ($resumeLog->position_seconds ?? 0));
                $watchActivity = $resumeLog->watched_at ?? $resumeLog->updated_at;
                $resume = [
                    'available' => true,
                    'lesson_id' => (int) $resumeLog->lesson_id,
                    'course_section_id' => (int) $resumeLog->course_section_id,
                    'lesson_title' => (string) ($resumeLog->lesson?->title ?? $resumeLog->lesson_name),
                    'thumbnail' => $resumeLog->lesson?->thumbnail_path,
                    'section_order' => (int) $resumeLog->courseSection?->order,
                    'position_seconds' => $position,
                    'duration_seconds' => $duration > 0 ? $duration : null,
                    'progress_percentage' => $duration > 0
                        ? min(100, round(($position / $duration) * 100, 2))
                        : null,
                    'watched_at' => $watchActivity?->toIso8601String(),
                ];
            }

            $nextSection = $sectionsByCourse->get($courseId, collect())->first(
                fn (CourseSection $section): bool => !$completedSectionIds->has((int) $section->id)
            );
            $next = null;
            if ($nextSection) {
                $next = [
                    'course_section_id' => (int) $nextSection->id,
                    'id' => (int) $nextSection->sectionable_id,
                    'type' => (string) $nextSection->getSectionType(),
                    'title' => (string) $nextSection->title,
                    'module_id' => $nextSection->module_id ? (int) $nextSection->module_id : null,
                    'order' => (int) $nextSection->order,
                ];
            }

            $progressActivity = $progressActivityByCourse->get($courseId);
            $lastActivity = collect([$watchActivity, $progressActivity])->filter()->max();

            return [
                'course_id' => $courseId,
                'title' => (string) $course->title,
                'image' => $course->image ? (string) $course->image : null,
                'progress_percentage' => $percentage,
                'completed_sections' => $completed,
                'total_sections' => $total,
                'is_completed' => $total > 0 && $completed === $total,
                'resume' => $resume,
                'next_section' => $next,
                'last_activity_at' => $lastActivity
                    ? Carbon::parse($lastActivity)->toIso8601String()
                    : null,
                'access_type' => (string) $entitlement['access_type'],
                'chat_available' => (bool) $entitlement['chat_available'],
                'certificate_included' => (bool) $certificateStatus['included'],
                'certificate_available' => (bool) $certificateStatus['available'],
                'access_granted_at' => $enrollment->access_granted_at?->toIso8601String(),
                'tags' => $course->classifications
                    ->map(fn ($classification): array => [
                        'name_ar' => $classification->name_ar,
                        'name_en' => $classification->name_en,
                    ])
                    ->values(),
            ];
        })->sort(function (array $left, array $right): int {
            if ($left['is_completed'] !== $right['is_completed']) {
                return $left['is_completed'] ? 1 : -1;
            }

            $activityOrder = strcmp(
                (string) ($right['last_activity_at'] ?? ''),
                (string) ($left['last_activity_at'] ?? '')
            );
            if ($activityOrder !== 0) {
                return $activityOrder;
            }

            $accessOrder = strcmp(
                (string) ($right['access_granted_at'] ?? ''),
                (string) ($left['access_granted_at'] ?? '')
            );

            return $accessOrder !== 0
                ? $accessOrder
                : ((int) $right['course_id'] <=> (int) $left['course_id']);
        })->values();

        return [
            'items' => $items,
            'pagination' => [
                'limit' => self::MAX_ACTIVE_COURSES,
                'has_more' => $hasMoreCourses,
            ],
        ];
    }
}
