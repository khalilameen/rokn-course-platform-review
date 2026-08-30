<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

final readonly class CourseCatalogueQueryService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(private CourseDurationService $duration)
    {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function cachedCatalogue(array $filters): LengthAwarePaginator
    {
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 20;
        $revision = max(1, (int) Cache::get('courses:catalog-revision', 1));
        $key = 'courses:' . md5((string) json_encode([
            'catalog_contract' => 3,
            'catalog_revision' => $revision,
            'page' => $page,
            'per_page' => $perPage,
            'grade_id' => $filters['grade_id'] ?? null,
            'type' => $filters['type'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
            'search' => $filters['search'] ?? null,
        ]));

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $courses = Cache::lock("lock:{$key}", 10)->block(
            3,
            function () use ($filters, $key, $page, $perPage): LengthAwarePaginator {
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                $courses = $this->applyFilters($this->catalogueQuery(), $filters)
                    ->orderByDesc('is_main_course')
                    ->orderBy('home_sort_order')
                    ->orderByDesc('created_at')
                    ->paginate((int) $perPage, ['*'], 'page', (int) $page);

                $this->duration->attachMany($courses->getCollection());

                return $courses;
            }
        );

        Cache::put($key, $courses, self::CACHE_TTL_SECONDS);

        return $courses;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function mobileCatalogue(array $filters): LengthAwarePaginator
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $revision = max(1, (int) Cache::get('courses:catalog-revision', 1));
        $key = 'courses:mobile:' . md5((string) json_encode([
            'catalog_contract' => 4,
            'catalog_revision' => $revision,
            'page' => $page,
            'per_page' => $perPage,
            'grade_id' => $filters['grade_id'] ?? null,
            'type' => $filters['type'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
            'search' => $filters['search'] ?? null,
        ]));

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $courses = Cache::lock("lock:{$key}", 10)->block(
            3,
            function () use ($filters, $key, $page, $perPage): LengthAwarePaginator {
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                $query = $this->catalogueQuery()->with([
                    'grade',
                    'sections' => function ($sections): void {
                        $sections
                            ->select('id', 'course_id', 'title', 'sectionable_type', 'order')
                            ->orderBy('order');
                    },
                ]);

                $courses = $this->applyFilters($query, $filters)
                    ->orderByDesc('is_main_course')
                    ->orderByDesc('created_at')
                    ->paginate($perPage, ['*'], 'page', $page);

                $this->duration->attachMany($courses->getCollection());

                return $courses;
            }
        );

        Cache::put($key, $courses, self::CACHE_TTL_SECONDS);

        return $courses;
    }

    private function catalogueQuery(): Builder
    {
        return Course::query()
            ->visibleInCatalog()
            ->where(function ($availability): void {
                $availability->where('is_coming_soon', true)
                    ->orWhereHas('sections');
            })
            ->with(['photo', 'coursePath', 'teachers.photo', 'classifications'])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->withCount('activeEnrollments')
            ->withCount('sections')
            ->withCount([
                'sections as preview_reels_count' => function ($sections): void {
                    $sections
                        ->where('sectionable_type', Lesson::class)
                        ->whereIn(
                            'sectionable_id',
                            Lesson::query()->select('id')->where('is_opened', true)
                        );
                },
            ]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['grade_id'] ?? null, function (Builder $courses, $gradeId): void {
                $courses->where('grade_id', $gradeId);
            })
            ->when($filters['type'] ?? null, function (Builder $courses, $type): void {
                $courses->where('type', $type);
            })
            ->when($filters['course_type'] ?? null, function (Builder $courses, $courseType): void {
                $courses->where('course_type', $courseType);
            })
            ->when($filters['search'] ?? null, function (Builder $courses, $search): void {
                $courses->where(function (Builder $names) use ($search): void {
                    $names->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%");
                });
            });
    }
}
