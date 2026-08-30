<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Models\Course;
use App\Models\Classification;
use App\Models\Lesson;
use App\Http\Resources\BaseCourseResource;
use App\Services\CourseDurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class HomeController extends Controller
{
    public function __construct(private readonly CourseDurationService $duration)
    {
    }

    public function mainPage(Request $request): JsonResponse
    {
        $settings = $this->getSettings();

        $grades = [];
        $gradeModels = $this->remember('home:grades:v1', 300, fn () => Grade::query()->get());
        foreach($gradeModels as $grade){
            $grades[] = [
                'id' => $grade->id,
                'title' => $grade->name_ar,
                'is_opened' => true,
                'description' => $grade->description_ar,
                'image' => $settings?->logo_url ? asset($settings->logo_url) : null,
            ];
        }

        $courses = $this->remember(
            'home:courses:v5:' . $this->catalogRevision(),
            120,
            fn () => Course::query()
                ->whereNull('parent_id')
                ->visibleInCatalog()
                ->where(function ($availability) {
                    $availability->where('is_coming_soon', true)
                        ->orWhereHas('sections');
                })
                ->with(['photo', 'teachers.photo', 'classifications', 'coursePath'])
                ->withCount(['sections as preview_reels_count' => function ($query) {
                    $query->where('sectionable_type', Lesson::class)
                        ->whereIn('sectionable_id', Lesson::query()->select('id')->where('is_opened', true));
                }])
                ->latest('created_at')
                ->limit(50)
                ->get()
        );
        $this->duration->attachMany($courses);

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "Home content retrieved successfully",
            "data" => [
                [
                    "courses" => BaseCourseResource::collection($courses),
                    "grades" => $grades,
                ]
            ]
        ]);
    }

    public function mobileMainPage(Request $request): JsonResponse
    {
        $catalogRevision = $this->catalogRevision();
        $mainCourses = $this->remember("mobile-home:main-courses:v4:{$catalogRevision}", 120, fn () => Course::query()
            ->whereNull('parent_id')
            ->where('is_main_course', true)
            ->where('is_coming_soon', false)
            ->visibleInCatalog()
            ->whereHas('sections')
            ->with(['photo', 'teachers.photo', 'classifications', 'coursePath'])
            ->withCount(['sections as preview_reels_count' => function ($query) {
                $query->where('sectionable_type', Lesson::class)
                    ->whereIn('sectionable_id', Lesson::query()->select('id')->where('is_opened', true));
            }])
            ->latest('created_at')
            ->limit(1)
            ->get());

        $hasHomeRowControls = Schema::hasColumn('classifications', 'show_on_home')
            && Schema::hasColumn('classifications', 'home_order');
        $hasCourseHomeOrder = Schema::hasColumn('courses', 'home_sort_order');

        $classifications = $this->remember(
            'mobile-home:classifications:v7:' . $catalogRevision . ':' . ($hasHomeRowControls ? 'managed' : 'legacy'),
            120,
            fn () => Classification::query()
                ->when($hasHomeRowControls, fn ($query) => $query->where('show_on_home', true))
                ->whereHas('courses', function ($query) {
                    $query->whereNull('parent_id')
                        ->visibleInCatalog()
                        ->where(function ($availability) {
                            $availability->where('is_coming_soon', true)
                                ->orWhereHas('sections');
                        });
                })
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
                "mobile-home:classification:{$classification->id}:courses:v7:{$catalogRevision}:" . ($hasCourseHomeOrder ? 'managed' : 'legacy'),
                120,
                fn () => $classification->courses()
                    ->whereNull('parent_id')
                    ->visibleInCatalog()
                    ->where(function ($availability) {
                        $availability->where('is_coming_soon', true)
                            ->orWhereHas('sections');
                    })
                    ->with(['photo', 'teachers.photo', 'classifications', 'coursePath'])
                    ->withCount(['sections as preview_reels_count' => function ($sectionQuery) {
                        $sectionQuery->where('sectionable_type', Lesson::class)
                            ->whereIn('sectionable_id', Lesson::query()->select('id')->where('is_opened', true));
                    }])
                    ->when(
                        $hasCourseHomeOrder,
                        fn ($query) => $query->orderBy('courses.home_sort_order')
                    )
                    ->latest('courses.created_at')
                    ->limit(15)
                    ->get()
            );

            if ($courses->isNotEmpty()) {
                $groupedCourses[$classification->name_ar] = $courses;
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
            "message" => "Mobile home content retrieved successfully",
            "data" => [
                "main_courses" => BaseCourseResource::collection($mainCourses),
                "courses" => $presentedGroups
            ]
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $designSettings = $this->getSettings() ?? new DesignSetting();
        $generalSettings = $this->remember('home:general-settings:v1', 60, fn () => Setting::first()) ?? new Setting();
        $supportWhatsAppUrl = $this->normalizeWhatsAppUrl(
            (string) ($generalSettings->support_whatsapp_url ?: $designSettings->whatsapp_url)
        );

        return response()->json([
            "status" => 200,
            "success" => true,
            "message" => "App settings retrieved successfully",
            "data" => [
                [
                    "name" => $designSettings->name_ar,
                    "social_media" => [
                        "facebook" => $designSettings->facebook_url,
                        "youtube" => $designSettings->youtube_url,
                        "instagram" => $designSettings->instagram_url,
                        "tiktok" => $designSettings->tiktok_url,
                        "whatsapp" => $supportWhatsAppUrl,
                        "telegram" => $designSettings->telegram_url,
                    ],
                    "support_contacts" => [
                        "technical" => $designSettings->technical_contact,
                        "whatsapp" => $supportWhatsAppUrl,
                    ],
                    // Stable canonical URLs keep the apps and store-review
                    // surfaces on the exact same published legal documents.
                    // The legacy content fields below remain for old clients.
                    "support_whatsapp_url" => $supportWhatsAppUrl,
                    "about_url" => route('about'),
                    "contact_url" => route('contact'),
                    "privacy_url" => route('privacy'),
                    "terms_url" => route('terms'),
                    "returns_policy_url" => route('returns-policy'),
                    "account_deletion_url" => route('account-deletion.show'),
                    "android_app_url" => $generalSettings->android_app_url,
                    "ios_app_url" => $generalSettings->ios_app_url,
                    "about_us_url" => $generalSettings->about_us_url ?: route('about'),
                    "privacy_policy_url" => $generalSettings->privacy_policy_url ?: route('privacy'),
                    "policy_content" => $designSettings->policy_content_ar,
                    "coin_rules" => $generalSettings->how_to_use_coins,
                ]
            ]
        ]);
    }

    private function getSettings(): ?DesignSetting
    {
        return $this->remember('home:design-settings:v1', 60, fn () => DesignSetting::first());
    }

    private function catalogRevision(): int
    {
        return max(1, (int) Cache::get('courses:catalog-revision', 1));
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

    private function normalizeWhatsAppUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            if (
                strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !in_array(strtolower((string) ($parts['host'] ?? '')), ['wa.me', 'www.wa.me'], true)
            ) {
                return null;
            }
            $digits = trim((string) ($parts['path'] ?? ''), '/');
        } else {
            $digits = preg_replace('/[\s()+.-]+/', '', $value) ?? '';
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1
            ? 'https://wa.me/' . $digits
            : null;
    }
}
