<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RoknLocale;

use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class CourseSearchService
{
    public function __construct(
        private ArabicSearchNormalizer $normalizer,
        private CourseCatalogueQueryService $catalogue,
        private CourseDurationService $duration
    ) {
    }

    public function apply(Builder $query, ?string $term): Builder
    {
        $raw = trim((string) $term);
        if ($raw === '') {
            return $query;
        }

        $normalized = $this->normalizer->normalize($raw);
        if (
            $normalized === ''
            || !Schema::hasColumn('courses', 'search_title_normalized')
            || !Schema::hasColumn('courses', 'search_terms_normalized')
        ) {
            $literal = addcslashes($raw, '\\%_');
            return $query->where(function (Builder $search) use ($literal) {
                $search->where('name_ar', 'like', "%{$literal}%")
                    ->orWhere('name_en', 'like', "%{$literal}%");
            });
        }

        $tokens = array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => mb_strlen($token) >= 2
        )));
        $relatedLiterals = array_map(
            fn (string $variant): string => addcslashes($variant, '\\%_'),
            $this->normalizer->relatedNameVariants($raw)
        );
        $query->where(function (Builder $search) use ($normalized, $tokens, $relatedLiterals) {
            $search->where('search_title_normalized', 'like', "%{$normalized}%")
                ->orWhere('search_terms_normalized', 'like', "%{$normalized}%")
                ->orWhereHas('teachers', function (Builder $teachers) use ($relatedLiterals): void {
                    $teachers->where('users.active', true)
                        ->where(function (Builder $names) use ($relatedLiterals): void {
                            $this->whereRelatedNameMatches($names, $relatedLiterals);
                        });
                })
                ->orWhereHas('teacher', function (Builder $teacher) use ($relatedLiterals): void {
                    $teacher->where('active', true)
                        ->whereIn('role', ['teacher', 'admin'])
                        ->where(function (Builder $names) use ($relatedLiterals): void {
                            $this->whereRelatedNameMatches($names, $relatedLiterals);
                        });
                })
                ->orWhereHas('classifications', function (Builder $classifications) use ($relatedLiterals): void {
                    $this->whereRelatedNameMatches(
                        $classifications,
                        $relatedLiterals,
                        ['name_ar', 'name_en']
                    );
                });

            if ($tokens !== []) {
                $search->orWhere(function (Builder $allTokens) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $allTokens->where(function (Builder $tokenMatch) use ($token): void {
                            $tokenMatch
                                ->where('search_title_normalized', 'like', "%{$token}%")
                                ->orWhere('search_terms_normalized', 'like', "%{$token}%");
                        });
                    }
                });
            }
        });

        // Exact and prefix title matches are intentionally deterministic. This
        // keeps Arabic search useful without an embedding service or opaque AI.
        return $query->orderByRaw(
            'CASE WHEN search_title_normalized = ? THEN 0 WHEN search_title_normalized LIKE ? THEN 1 ELSE 2 END',
            [$normalized, $normalized . '%']
        );
    }

    /** @param list<string> $literals @param list<string> $columns */
    private function whereRelatedNameMatches(
        Builder $query,
        array $literals,
        array $columns = ['name', 'name_ar', 'name_en']
    ): void {
        foreach ($literals as $literalIndex => $literal) {
            foreach ($columns as $columnIndex => $column) {
                $method = $literalIndex === 0 && $columnIndex === 0
                    ? 'where'
                    : 'orWhere';
                $query->{$method}($column, 'like', "%{$literal}%");
            }
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function results(array $filters): array
    {
        $key = 'course-search:v3:' . hash('sha256', (string) json_encode([
            'revision' => $this->catalogue->revision(),
            'locale' => app()->getLocale(),
            'q' => $this->normalizer->normalize((string) ($filters['q'] ?? '')),
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 12),
            'classification_id' => $filters['classification_id'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
        ]));
        $build = fn (): array => $this->buildResults($filters);

        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }

            return Cache::lock("lock:{$key}", 10)->block(2, function () use ($key, $build): array {
                return Cache::remember($key, 120, $build);
            });
        } catch (Throwable) {
            return $build();
        }
    }

    /** @param array<string, mixed> $filters */
    private function buildResults(array $filters): array
    {
        $query = $this->catalogue
            ->applyPublicContract(Course::query())
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

        $results = $this->catalogue->orderForDiscovery(
            $this->apply($query, (string) $filters['q'])
        )
            ->paginate((int) ($filters['per_page'] ?? 12));
        $this->duration->attachMany($results->getCollection());

        $items = $results->getCollection()->map(function (Course $course): array {
            $teacher = $course->teachers->first() ?: $course->teacher;

            return [
                'course_id' => (int) $course->id,
                'title' => (string) $course->title,
                'image' => $course->image ? (string) $course->image : null,
                'teacher_name' => $teacher ? (string) $teacher->name : null,
                'badge' => RoknLocale::isArabic()
                    ? ($course->catalog_badge_ar ?: $course->catalog_badge_en)
                    : ($course->catalog_badge_en ?: $course->catalog_badge_ar),
                'badge_tone' => $course->catalog_badge_tone ?: 'neutral',
                'is_coming_soon' => (bool) $course->is_coming_soon,
                'preview_count' => (int) $course->preview_reels_count,
                'duration_minutes' => max(0, (int) ($course->duration_minutes_computed ?? 0)),
                'ratings_count' => (int) $course->ratings_count,
                'rating_average' => round((float) ($course->ratings_avg_rating ?? 0), 1),
                'average_rating' => $course->ratings_count > 0
                    ? round((float) ($course->ratings_avg_rating ?? 0), 1)
                    : null,
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
