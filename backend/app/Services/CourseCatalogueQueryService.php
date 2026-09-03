<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class CourseCatalogueQueryService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private CourseDurationService $duration,
        private ArabicSearchNormalizer $searchNormalizer
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function cachedCatalogue(array $filters): LengthAwarePaginator
    {
        $filters['search'] = $this->canonicalSearchInput($filters['search'] ?? null);
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 20;
        $revision = $this->revision();
        $key = 'courses:' . md5((string) json_encode([
            'catalog_contract' => 7,
            'catalog_revision' => $revision,
            'page' => $page,
            'per_page' => $perPage,
            'grade_id' => $filters['grade_id'] ?? null,
            'type' => $filters['type'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
            'search' => $this->normalizedSearchKey($filters['search'] ?? null),
        ]));

        $build = function () use ($filters, $page, $perPage): LengthAwarePaginator {
            $courses = $this->orderForDiscovery(
                $this->applyFilters($this->catalogueQuery(), $filters)
            )
                ->paginate((int) $perPage, ['*'], 'page', (int) $page);
            $this->duration->attachMany($courses->getCollection());

            return $courses;
        };

        return $this->rememberPaginator($key, $build);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function mobileCatalogue(array $filters): LengthAwarePaginator
    {
        $filters['search'] = $this->canonicalSearchInput($filters['search'] ?? null);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $revision = $this->revision();
        $key = 'courses:mobile:' . md5((string) json_encode([
            'catalog_contract' => 8,
            'catalog_revision' => $revision,
            'page' => $page,
            'per_page' => $perPage,
            'grade_id' => $filters['grade_id'] ?? null,
            'type' => $filters['type'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
            'search' => $this->normalizedSearchKey($filters['search'] ?? null),
        ]));

        $build = function () use ($filters, $page, $perPage): LengthAwarePaginator {
            // Catalogue cards consume aggregate counts only. Loading sections
            // here made every page serialize the complete curriculum outline
            // for every card, even though the phone opens that graph only from
            // course details. Keep the list bounded independently of course size.
            $query = $this->catalogueQuery();

            $courses = $this->orderForDiscovery(
                $this->applyFilters($query, $filters)
            )
                ->paginate($perPage, ['*'], 'page', $page);
            $this->duration->attachMany($courses->getCollection());

            return $courses;
        };

        return $this->rememberPaginator($key, $build);
    }

    /** @param callable():LengthAwarePaginator $build */
    private function rememberPaginator(string $key, callable $build): LengthAwarePaginator
    {
        try {
            $cached = Cache::get($key);
            if ($cached instanceof LengthAwarePaginator) {
                return $cached;
            }

            return Cache::lock("lock:{$key}", 10)->block(3, function () use ($key, $build) {
                $cached = Cache::get($key);
                if ($cached instanceof LengthAwarePaginator) {
                    return $cached;
                }

                $courses = $build();
                Cache::put($key, $courses, self::CACHE_TTL_SECONDS);

                return $courses;
            });
        } catch (Throwable) {
            // Redis accelerates discovery; it never owns catalogue availability.
            return $build();
        }
    }

    public function revision(): int
    {
        try {
            $key = 'courses:catalog-revision';
            Cache::add(
                $key,
                max(1, (int) floor(microtime(true) * 1000)),
                now()->addYears(10)
            );

            return max(1, (int) Cache::get($key));
        } catch (Throwable) {
            // Cache-backed pages are unavailable in the same failure mode, so
            // this value is response metadata only and cannot resurrect data.
            return 1;
        }
    }

    private function normalizedSearchKey(mixed $value): ?string
    {
        $normalized = $this->searchNormalizer->normalize((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    public function catalogueQuery(): Builder
    {
        return $this->applyPublicContract(Course::query());
    }

    /**
     * One public catalogue boundary for home rows, paths, grades and search.
     * A relation-specific endpoint must not accidentally expose a draft,
     * child record or a course with no learning map.
     */
    public function applyPublicContract(Builder $query): Builder
    {
        $query = $this->applyPublicBoundary($query)
            // BaseCourseResource asks whether a row is nested before exposing
            // a share URL. Carry that fact in the catalogue query so rendering
            // hundreds of cards never performs one existence query per card.
            ->withExists('courseSection')
            ->with([
                'photo',
                'coursePath',
                'teacher' => fn ($teacher) => $teacher
                    ->where('active', true)
                    ->whereIn('role', ['teacher', 'admin']),
                'teacher.photo',
                'teachers' => fn ($teachers) => $teachers
                    ->where('users.active', true)
                    ->orderBy('users.id'),
                'teachers.photo',
                'classifications',
            ])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->withCount('activeEnrollments')
            ->withCount('sections')
            ->withCount([
                'sections as video_reels_count' => function ($sections): void {
                    $sections->where('sectionable_type', Lesson::class);
                },
                'sections as preview_reels_count' => function ($sections): void {
                    $sections
                        ->where('sectionable_type', Lesson::class)
                        ->whereIn(
                            'sectionable_id',
                            Lesson::query()->select('id')->where('is_opened', true)
                        );
                },
            ]);

        return $this->withPublicPlanFacts($query);
    }

    /**
     * Catalogue cards expose only aggregated commercial facts. The three
     * mutable plan contracts remain exclusive to course details.
     */
    public function withPublicPlanFacts(Builder $query): Builder
    {
        if (!DatabaseCapabilities::hasTable('course_access_plans')) {
            return $query;
        }

        return $query
            ->withMin([
                'accessPlans as catalog_min_price_coins' => fn (Builder $plans) =>
                    $plans->where('is_active', true),
            ], 'price_coins')
            ->withExists([
                'accessPlans as catalog_has_active_plans' => fn (Builder $plans) =>
                    $plans->where('is_active', true),
                'accessPlans as catalog_chat_available' => fn (Builder $plans) =>
                    $plans->where('is_active', true)->where('chat_enabled', true),
            ]);
    }

    /** A lightweight copy for whereHas/count subqueries. */
    public function constrainPublic(Builder $query): Builder
    {
        return $this->applyPublicBoundary($query);
    }

    /**
     * The same predicate powers lists, relation counts and direct details.
     * A malformed legacy row is filtered before pagination, so the phone does
     * not receive a short page after dropping an unusable card locally.
     */
    private function applyPublicBoundary(Builder $query): Builder
    {
        $query = $query
            ->whereNull('parent_id')
            ->whereDoesntHave('courseSection')
            ->visibleInCatalog()
            ->where(function (Builder $identity): void {
                $identity->where(function (Builder $arabic): void {
                    $arabic->whereNotNull('name_ar')->where('name_ar', '<>', '');
                })->orWhere(function (Builder $english): void {
                    $english->whereNotNull('name_en')->where('name_en', '<>', '');
                });
            })
            ->where(function (Builder $description): void {
                $description->where(function (Builder $arabic): void {
                    $arabic->whereNotNull('description_ar')->where('description_ar', '<>', '');
                })->orWhere(function (Builder $english): void {
                    $english->whereNotNull('description_en')->where('description_en', '<>', '');
                });
            })
            ->where(function (Builder $cover): void {
                $cover->whereHas('photo')
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNotNull('image')->where('image', '<>', '');
                    });
            })
            // Existing production courses predate the classification and
            // multi-teacher pivots. Their public contract has always allowed
            // empty tags/teachers and the app supplies the neutral instructor
            // label. Keep those already-published rows discoverable during a
            // rolling deploy; CoursePublishingService still requires both
            // relations before any new course can be published.
            ->where(function ($availability): void {
                $availability->where('is_coming_soon', true)
                    ->orWhere(function (Builder $published): void {
                        $published->where('is_coming_soon', false)
                            ->whereHas('sections');

                        if (DatabaseCapabilities::hasTable('course_access_plans')) {
                            $published->whereHas(
                                'accessPlans',
                                fn (Builder $plans) => $plans->where('is_active', true)
                            );
                        }
                    });
            });

        return $query;
    }

    public function isPubliclyDiscoverable(int $courseId): bool
    {
        return $this->constrainPublic(Course::query())
            ->whereKey($courseId)
            ->exists();
    }

    /** A total order prevents page drift when several courses share a rank. */
    public function orderForDiscovery(Builder $query, bool $heroFirst = true): Builder
    {
        if ($heroFirst) {
            $query->orderByDesc('courses.is_main_course');
        }

        return $query
            ->orderBy('courses.home_sort_order')
            ->orderByDesc('courses.created_at')
            ->orderByDesc('courses.id');
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
                $this->applySearch($courses, (string) $search);
            });
    }

    private function canonicalSearchInput(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private function applySearch(Builder $courses, string $raw): void
    {
        $normalized = $this->searchNormalizer->normalize($raw);
        if (
            $normalized === ''
            ||
            !DatabaseCapabilities::hasColumn('courses', 'search_title_normalized')
            || !DatabaseCapabilities::hasColumn('courses', 'search_terms_normalized')
        ) {
            $literal = addcslashes($raw, '\\%_');
            $courses->where(function (Builder $names) use ($literal): void {
                $names->where('name_ar', 'like', "%{$literal}%")
                    ->orWhere('name_en', 'like', "%{$literal}%");
            });
            return;
        }

        $tokens = array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => mb_strlen($token) >= 2
        )));
        $relatedLiterals = array_map(
            fn (string $variant): string => addcslashes($variant, '\\%_'),
            $this->searchNormalizer->relatedNameVariants($raw)
        );
        $courses->where(function (Builder $search) use ($normalized, $tokens, $relatedLiterals): void {
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
                        $allTokens->where(function (Builder $match) use ($token): void {
                            $match->where('search_title_normalized', 'like', "%{$token}%")
                                ->orWhere('search_terms_normalized', 'like', "%{$token}%");
                        });
                    }
                });
            }
        });
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
}
