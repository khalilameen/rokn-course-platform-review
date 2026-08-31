<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final readonly class CourseSearchService
{
    public function __construct(private ArabicSearchNormalizer $normalizer)
    {
    }

    public function apply(Builder $query, ?string $term): Builder
    {
        $raw = trim((string) $term);
        if ($raw === '') {
            return $query;
        }

        $normalized = $this->normalizer->normalize($raw);
        if ($normalized === '' || !Schema::hasColumn('courses', 'search_title_normalized')) {
            return $query->where(function (Builder $search) use ($raw) {
                $search->where('name_ar', 'like', "%{$raw}%")
                    ->orWhere('name_en', 'like', "%{$raw}%");
            });
        }

        $tokens = array_values(array_unique(array_filter(explode(' ', $normalized))));
        $query->where(function (Builder $search) use ($normalized, $tokens) {
            $search->where('search_title_normalized', 'like', "%{$normalized}%")
                ->orWhere('search_terms_normalized', 'like', "%{$normalized}%");

            foreach ($tokens as $token) {
                if (mb_strlen($token) < 2) {
                    continue;
                }
                $search->orWhere('search_title_normalized', 'like', "%{$token}%");
            }
        });

        // Exact and prefix title matches are intentionally deterministic. This
        // keeps Arabic search useful without an embedding service or opaque AI.
        return $query->orderByRaw(
            'CASE WHEN search_title_normalized = ? THEN 0 WHEN search_title_normalized LIKE ? THEN 1 ELSE 2 END',
            [$normalized, $normalized . '%']
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function results(array $filters): array
    {
        $query = Course::query()
            ->visibleInCatalog()
            ->where(function (Builder $availability): void {
                $availability->where('is_coming_soon', true)->orWhereHas('sections');
            })
            ->with(['teachers:id,name,name_ar,name_en'])
            ->withCount([
                'ratings',
                'activeEnrollments',
                'sections as preview_count' => function ($sections): void {
                    $sections->where('sectionable_type', Lesson::class)
                        ->whereIn(
                            'sectionable_id',
                            Lesson::query()->select('id')->where('is_opened', true)
                        );
                },
            ])
            ->withAvg('ratings', 'rating')
            ->when(
                $filters['classification_id'] ?? null,
                function (Builder $courses, $classificationId): void {
                    $courses->whereHas(
                        'classifications',
                        fn (Builder $classifications) => $classifications->whereKey($classificationId)
                    );
                }
            )
            ->when(
                $filters['course_type'] ?? null,
                fn (Builder $courses, $type) => $courses->where('course_type', $type)
            );

        $results = $this->apply($query, (string) $filters['q'])
            ->orderByDesc('is_main_course')
            ->orderBy('home_sort_order')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 12));

        $items = $results->getCollection()->map(function (Course $course): array {
            $teacher = $course->teachers->first();

            return [
                'course_id' => (int) $course->id,
                'title' => (string) $course->title,
                'image' => $course->image ? (string) $course->image : null,
                'teacher_name' => $teacher ? (string) $teacher->name : null,
                'badge' => $course->catalog_badge_ar ?: $course->catalog_badge_en,
                'badge_tone' => $course->catalog_badge_tone ?: 'neutral',
                'is_coming_soon' => (bool) $course->is_coming_soon,
                'preview_count' => (int) $course->preview_count,
                'ratings_count' => (int) $course->ratings_count,
                'rating_average' => round((float) ($course->ratings_avg_rating ?? 0), 1),
                'students_count' => (int) $course->active_enrollments_count,
            ];
        })->values();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
        ];
    }
}
