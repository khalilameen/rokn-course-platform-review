<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\WatchingLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class LearningDashboardService
{
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
            ->with([
                'course.photo',
                'course.classifications',
                'order.courseCode',
                'financialHolds' => fn ($query) => $query->where('status', 'active'),
            ])
            ->latest('access_granted_at')
            ->get()
            ->filter(fn (CourseEnrollment $enrollment): bool => (bool) $enrollment->course);

        $courseIds = $enrollments
            ->pluck('course_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $sectionCourse = CourseSection::query()
            ->whereIn('course_id', $courseIds)
            ->pluck('course_id', 'id');
        $totalByCourse = $sectionCourse->values()->countBy();
        $progressRows = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $sectionCourse->keys())
            ->get(['course_section_id', 'is_completed', 'updated_at']);
        $completedByCourse = $progressRows
            ->where('is_completed', true)
            ->unique('course_section_id')
            ->map(fn ($progress) => $sectionCourse->get($progress->course_section_id))
            ->filter()
            ->countBy();
        $progressActivityByCourse = $progressRows
            ->groupBy(fn ($progress) => $sectionCourse->get($progress->course_section_id))
            ->map(fn ($rows) => $rows->max('updated_at'));

        $resumeByCourse = collect();
        if ((bool) $user->watch_history_enabled && $courseIds->isNotEmpty()) {
            $resumeByCourse = WatchingLog::query()
                ->where('user_id', $user->id)
                ->whereIn('course_id', $courseIds)
                ->with([
                    'lesson:id,list_id,title,title_ar,title_en,thumbnail_path',
                    'courseSection:id,course_id,sectionable_type,sectionable_id,order',
                ])
                ->orderByRaw('COALESCE(watched_at, updated_at) DESC')
                ->orderByDesc('id')
                ->get()
                ->filter(function (WatchingLog $log): bool {
                    return $log->lesson !== null
                        && $log->courseSection !== null
                        && (int) $log->lesson->list_id === (int) $log->course_id
                        && (int) $log->courseSection->course_id === (int) $log->course_id
                        && (int) $log->courseSection->sectionable_id === (int) $log->lesson_id;
                })
                ->unique(fn (WatchingLog $log): int => (int) $log->course_id)
                ->keyBy(fn (WatchingLog $log): int => (int) $log->course_id);
        }

        $items = $enrollments->map(function (CourseEnrollment $enrollment) use (
            $totalByCourse,
            $completedByCourse,
            $progressActivityByCourse,
            $resumeByCourse
        ): array {
            $course = $enrollment->course;
            $courseId = (int) $course->id;
            $total = (int) $totalByCourse->get($courseId, 0);
            $completed = min($total, (int) $completedByCourse->get($courseId, 0));
            $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;
            $order = $enrollment->order;
            $courseHeld = $enrollment->financialHolds->contains(
                fn ($hold): bool => $hold->entitlement_scope === 'course'
            );
            $chatHeld = $courseHeld || $enrollment->financialHolds->contains(
                fn ($hold): bool => $hold->entitlement_scope === 'chat'
            );
            $isCourseCode = $order
                && $order->status === Order::STATUS_APPROVED
                && $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE;
            $isGrant = $isCourseCode && (bool) $order->courseCode?->isInstitutionalGrant();
            $isPaid = $order
                && $order->status === Order::STATUS_APPROVED
                && !$isCourseCode
                && ((int) $order->total_coins > 0 || (float) $order->final_amount > 0);
            $accessType = $isPaid
                ? 'paid'
                : ($isGrant ? 'scholarship' : ($isCourseCode ? 'course_code' : 'free'));

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
                'last_activity_at' => $lastActivity
                    ? Carbon::parse($lastActivity)->toIso8601String()
                    : null,
                'access_type' => $accessType,
                'chat_available' => !$chatHeld
                    && (bool) $course->ai_chat_enabled
                    && ($isPaid || ($isCourseCode && !$isGrant)),
                'certificate_available' => !$courseHeld && !$isGrant,
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

            return strcmp(
                (string) ($right['last_activity_at'] ?? ''),
                (string) ($left['last_activity_at'] ?? '')
            );
        })->values();

        return ['items' => $items];
    }
}
