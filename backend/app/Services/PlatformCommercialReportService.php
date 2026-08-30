<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;

/** Platform-wide unit economics assembled from the same auditable course ledger. */
final readonly class PlatformCommercialReportService
{
    public function __construct(private CourseCommercialReportService $courses)
    {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function report(array $filters = []): array
    {
        $courseModels = Course::query()
            ->whereNull('parent_id')
            ->whereHas('enrollments')
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) =>
                $query->whereKey((int) $courseId)
            )
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en']);

        $rawRows = collect();
        $warnings = collect();
        foreach ($courseModels as $course) {
            $courseReport = $this->courses->forCourse($course);
            $warnings = $warnings->concat($courseReport['cost_warnings']);
            $rawRows = $rawRows->concat(
                $courseReport['rows']->map(function (array $row) use ($course): array {
                    $row['course_id'] = (int) $course->id;
                    $row['course_name'] = (string) $course->title;

                    return $row;
                })
            );
        }

        $filterOptions = [
            'plans' => $rawRows->map(fn (array $row): array => [
                'code' => (string) $row['plan_code'],
                'name' => (string) $row['plan_name'],
            ])->unique(fn (array $plan): string => $plan['code'].'|'.$plan['name'])->values(),
            'sources' => $rawRows->map(fn (array $row): array => [
                'code' => (string) $row['source'],
                'name' => (string) $row['source_label'],
            ])->unique('code')->values(),
        ];

        $rows = $this->filterRows($rawRows, $filters);
        $summary = $this->courses->groupSummary($rows);
        $studentRows = $rows
            ->groupBy(fn (array $row): int => (int) $row['enrollment']->user_id)
            ->map(function (Collection $userRows): array {
                $first = $userRows->first();

                return $this->courses->groupSummary($userRows) + [
                    'user' => $first['user'],
                    'active_courses' => $userRows->where('is_active', true)->count(),
                    'courses' => $userRows->pluck('course_name')->filter()->unique()->values(),
                    'plans' => $userRows->pluck('plan_name')->filter()->unique()->values(),
                    'sources' => $userRows->pluck('source_label')->filter()->unique()->values(),
                    'actual_cost_by_service_egp' => $this->sumServiceMaps(
                        $userRows,
                        'actual_cost_by_service_egp'
                    ),
                    'cost_with_estimates_by_service_egp' => $this->sumServiceMaps(
                        $userRows,
                        'cost_with_estimates_by_service_egp'
                    ),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) ($row['service_cost_egp'] ?? -1))
            ->values();
        $uniqueStudents = $studentRows->count();
        $summary['average_net_per_student_egp'] = $uniqueStudents > 0
            && $summary['net_egp'] !== null
                ? round((float) $summary['net_egp'] / $uniqueStudents, 2)
                : null;
        $summary['average_cost_per_student_egp'] = $uniqueStudents > 0
            && $summary['service_cost_egp'] !== null
                ? round((float) $summary['service_cost_egp'] / $uniqueStudents, 2)
                : null;
        $summary['ai_cost_per_1000_tokens_usd'] = (int) $summary['ai_tokens'] > 0
            ? round(((float) $summary['ai_cost_usd'] / (int) $summary['ai_tokens']) * 1000, 6)
            : null;
        $serviceBreakdown = $this->serviceBreakdown($rows)->map(function (array $service) use (
            $summary
        ): array {
            $service['share_of_actual_cost_percentage'] = $summary['service_cost_egp'] !== null
                && (float) $summary['service_cost_egp'] > 0
                && $service['actual_egp'] !== null
                    ? round(((float) $service['actual_egp'] / (float) $summary['service_cost_egp']) * 100, 2)
                    : null;

            return $service;
        });

        return $summary + [
            'rows' => $rows,
            'student_rows' => $studentRows,
            'unique_students' => $uniqueStudents,
            'enrollments' => $rows->count(),
            'active_enrollments' => $rows->where('is_active', true)->count(),
            'course_breakdown' => $rows->groupBy('course_id')->map(function (
                Collection $courseRows
            ): array {
                return $this->courses->groupSummary($courseRows) + [
                    'course_id' => (int) $courseRows->first()['course_id'],
                    'course_name' => (string) $courseRows->first()['course_name'],
                ];
            })->values(),
            'plan_breakdown' => $rows->groupBy('plan_name')->map(
                fn (Collection $planRows): array => $this->courses->groupSummary($planRows)
            ),
            'source_breakdown' => $rows->groupBy('source_label')->map(
                fn (Collection $sourceRows): array => $this->courses->groupSummary($sourceRows)
            ),
            'service_breakdown' => $serviceBreakdown,
            'cost_warnings' => $warnings->filter()->unique()->values(),
            'filter_options' => $filterOptions,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @param array<string, mixed> $filters */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        $plan = trim((string) ($filters['plan'] ?? ''));
        $source = trim((string) ($filters['source'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return $rows
            ->when($plan !== '', fn (Collection $items) => $items->filter(
                fn (array $row): bool => (string) $row['plan_code'] === $plan
                    || (string) $row['plan_name'] === $plan
            ))
            ->when($source !== '', fn (Collection $items) => $items->where('source', $source))
            ->when($search !== '', fn (Collection $items) => $items->filter(function (
                array $row
            ) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($row['user']?->name ?? ''),
                    (string) ($row['user']?->email ?? ''),
                    (string) $row['course_name'],
                    (string) $row['plan_name'],
                ]));

                return str_contains($haystack, $search);
            }))
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<string, float|null> */
    private function sumServiceMaps(Collection $rows, string $field): Collection
    {
        return collect(CourseCostReportService::serviceLabels())->map(function (
            string $_label,
            string $serviceKey
        ) use ($rows, $field): ?float {
            if ($rows->contains(fn (array $row): bool =>
                ($row[$field][$serviceKey] ?? null) === null
            )) {
                return null;
            }

            return round((float) $rows->sum(fn (array $row): float =>
                (float) ($row[$field][$serviceKey] ?? 0)
            ), 4);
        });
    }

    /** @param Collection<int, array<string, mixed>> $rows @return Collection<int, array<string, mixed>> */
    private function serviceBreakdown(Collection $rows): Collection
    {
        $actual = $this->sumServiceMaps($rows, 'actual_cost_by_service_egp');
        $estimated = $this->sumServiceMaps($rows, 'cost_with_estimates_by_service_egp');

        return collect(CourseCostReportService::serviceLabels())->map(function (
            string $label,
            string $serviceKey
        ) use ($actual, $estimated, $rows): array {
            $result = [
                'key' => $serviceKey,
                'label' => $label,
                'actual_egp' => $actual->get($serviceKey),
                'with_estimates_egp' => $estimated->get($serviceKey),
            ];
            if ($serviceKey === CourseCostReportService::OPENROUTER_SERVICE) {
                $result += [
                    'requests' => (int) $rows->sum('ai_requests'),
                    'failed_requests' => (int) $rows->sum('ai_failed_requests'),
                    'units' => (int) $rows->sum('ai_tokens'),
                    'unit_label' => 'توكن',
                    'cost_usd' => round((float) $rows->sum('ai_cost_usd'), 6),
                ];
            } elseif ($serviceKey === 'bunny_delivery') {
                $result += [
                    'units' => round((float) $rows->sum('playback_gb_estimated'), 4),
                    'unit_label' => 'GB مشاهدة مقدرة',
                    'minutes' => round((float) $rows->sum('playback_minutes'), 2),
                ];
            }

            return $result;
        })->values();
    }
}
