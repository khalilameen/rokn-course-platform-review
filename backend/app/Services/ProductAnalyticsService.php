<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ProductAnalyticsService
{
    public function funnel(?int $courseId = null, int $days = 30): array
    {
        $from = CarbonImmutable::now()->subDays(max(1, min($days, 365)))->startOfDay();
        $events = [
            'course_impression', 'course_opened', 'sample_started', 'sample_completed',
            'paywall_viewed', 'earn_tasks_opened', 'purchase_started', 'purchase_completed',
            'project_submitted', 'project_passed', 'certificate_issued',
        ];

        $query = ProductEvent::query()
            ->where('occurred_at', '>=', $from)
            ->whereIn('event_name', $events);
        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $counts = $query->selectRaw('event_name, COUNT(*) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        $uniqueActors = ProductEvent::query()
            ->where('occurred_at', '>=', $from)
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
            ->whereNotNull('actor_key')
            ->selectRaw('event_name, COUNT(DISTINCT actor_key) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        return [
            'from' => $from->toDateString(),
            'days' => $days,
            'course_id' => $courseId,
            'steps' => collect($events)->map(function (string $event) use ($counts, $uniqueActors) {
                return [
                    'event' => $event,
                    'total' => (int) ($counts[$event] ?? 0),
                    'unique_actors' => (int) ($uniqueActors[$event] ?? 0),
                ];
            })->values()->all(),
        ];
    }

    public function lessonDropOff(?int $courseId = null, int $days = 30): Collection
    {
        return ProductEvent::query()
            ->where('occurred_at', '>=', now()->subDays(max(1, min($days, 365))))
            ->whereIn('event_name', ['lesson_started', 'lesson_completed'])
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId))
            ->whereNotNull('lesson_id')
            ->selectRaw("lesson_id, SUM(CASE WHEN event_name = 'lesson_started' THEN 1 ELSE 0 END) starts, SUM(CASE WHEN event_name = 'lesson_completed' THEN 1 ELSE 0 END) completions")
            ->groupBy('lesson_id')
            ->orderByDesc('starts')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $starts = (int) $row->starts;
                $completions = (int) $row->completions;
                return [
                    'lesson_id' => (int) $row->lesson_id,
                    'starts' => $starts,
                    'completions' => $completions,
                    'completion_rate' => $starts > 0 ? round(($completions / $starts) * 100, 1) : 0.0,
                ];
            });
    }
}
