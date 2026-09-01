<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Models\Course;
use App\Models\Classification;
use App\Http\Resources\BaseCourseResource;
use App\Services\CourseDurationService;
use App\Services\CourseCatalogueQueryService;
use App\Services\PublicAppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Support\RoknLocale;

final class HomeController extends Controller
{
    public function __construct(
        private readonly CourseDurationService $duration,
        private readonly CourseCatalogueQueryService $catalogue,
        private readonly PublicAppSettingsService $publicSettings
    ) {
    }

    public function mainPage(Request $request): JsonResponse
    {
        $grades = [];
        $gradeModels = $this->remember(
            'home:grades:v2:' . $this->catalogue->revision(),
            300,
            fn () => Grade::query()
                ->active()
                ->whereHas(
                    'courses',
                    fn ($courses) => $this->catalogue->constrainPublic($courses)
                )
                ->ordered()
                ->get()
        );
        foreach($gradeModels as $grade){
            $grades[] = [
                'id' => $grade->id,
                'title' => $grade->name,
                'is_opened' => true,
                'description' => $grade->description,
                'image' => null,
            ];
        }

        $courses = $this->remember(
            'home:courses:v7:' . $this->catalogue->revision(),
            120,
            fn () => $this->catalogue->orderForDiscovery(
                $this->catalogue->applyPublicContract(Course::query())
            )
                ->limit(50)
                ->get()
        );
        $this->duration->attachMany($courses);

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "تم تحميل الصفحة الرئيسية",
            "data" => [
                [
                    "courses" => BaseCourseResource::collection($courses),
                    "grades" => $grades,
                    "catalogue_revision" => $this->catalogue->revision(),
                ]
            ]
        ]);
    }

    public function mobileMainPage(Request $request): JsonResponse
    {
        $catalogRevision = $this->catalogue->revision();
        $mainCourses = $this->remember(
            "mobile-home:main-courses:v7:{$catalogRevision}",
            120,
            fn () => $this->catalogue->orderForDiscovery(
                $this->catalogue->applyPublicContract(Course::query())
                    ->where('is_main_course', true)
                    ->where('is_coming_soon', false)
            )->limit(1)->get()
        );

        $hasHomeRowControls = Schema::hasColumn('classifications', 'show_on_home')
            && Schema::hasColumn('classifications', 'home_order');
        $hasCourseHomeOrder = Schema::hasColumn('courses', 'home_sort_order');

        $classifications = $this->remember(
            'mobile-home:classifications:v8:' . $catalogRevision . ':' . ($hasHomeRowControls ? 'managed' : 'legacy'),
            120,
            fn () => Classification::query()
                ->when($hasHomeRowControls, fn ($query) => $query->where('show_on_home', true))
                ->whereHas(
                    'courses',
                    fn ($courses) => $this->catalogue->constrainPublic($courses)
                )
                ->when($hasHomeRowControls, fn ($query) => $query->orderBy('home_order'))
                ->orderBy('id')
                ->limit(20)
                ->get()
        );

        $groupedCourses = [];
        foreach ($classifications as $classification) {
            // Laravel 9 cannot safely apply an eager-load limit per parent.
            // Query each of the at-most-20 rows with a database LIMIT instead
            // of loading every course in the classification and slicing it in
            // PHP. The result is bounded to 300 cards even with a huge catalog.
            $courses = $this->remember(
                "mobile-home:classification:{$classification->id}:courses:v10:{$catalogRevision}:" . ($hasCourseHomeOrder ? 'managed' : 'legacy'),
                120,
                fn () => $this->catalogue->orderForDiscovery(
                    // Keep the relation's pivot constraint while handing the
                    // catalogue service the Eloquent builder it contracts on.
                    $this->catalogue->applyPublicContract(
                        $classification->courses()->getQuery()
                    ),
                    false
                )
                    ->limit(15)
                    ->get()
            );

            if ($courses->isNotEmpty()) {
                $classificationName = RoknLocale::isArabic()
                    ? ($classification->name_ar ?: $classification->name_en)
                    : ($classification->name_en ?: $classification->name_ar);
                if (trim((string) $classificationName) !== '') {
                    $groupedCourses[(string) $classificationName] = $courses;
                }
            }
        }

        $allCourses = $mainCourses
            ->concat(collect($groupedCourses)->flatten(1))
            ->unique(fn (Course $course): int => (int) $course->getKey())
            ->values();
        $this->duration->attachMany($allCourses);
        $presentedGroups = collect($groupedCourses)
            ->map(fn ($courses) => BaseCourseResource::collection($courses))
            ->all();

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "تم تحميل الصفحة الرئيسية",
            "data" => [
                "main_courses" => BaseCourseResource::collection($mainCourses),
                "courses" => $presentedGroups,
                "catalogue_revision" => $catalogRevision,
            ]
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = $this->publicSettings->snapshot();

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "تم تحميل إعدادات التطبيق",
            "data" => [$settings]
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=300',
            'ETag' => '"'.(string) $settings['revision'].'"',
            'Vary' => 'Accept-Language',
        ]);
    }

    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            return Cache::lock("lock:{$key}", 15)->block(2, function () use ($key, $seconds, $callback) {
                return Cache::remember($key, now()->addSeconds($seconds), $callback);
            });
        } catch (\Throwable $exception) {
            // Cache/lock outages must degrade to the database, never to a
            // broken home screen.
            return $callback();
        }
    }

}
