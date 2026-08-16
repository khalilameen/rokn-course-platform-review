<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\DesignSetting;
use App\Models\Level;
use App\Models\Order;
use App\Models\Path;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePublishingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CourseController extends Controller
{
    private const PROTECTED_COURSE_FIELDS = [
        'price',
        'students_count',
        'is_main_course',
        'home_sort_order',
        'catalog_badge_ar',
        'catalog_badge_en',
        'catalog_badge_tone',
        'ai_model_type',
        'temperature',
        'tokens_number',
        'ai_chat_enabled',
        'access_plans',
    ];

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(CoursePublishingService $publishingService)
    {
        $canViewFinance = $this->isAdministrator();
        $allocationScope = function ($query): void {
            $query->where('status', Order::STATUS_APPROVED)
                ->whereIn('payment_method', [
                    Order::PAYMENT_METHOD_WALLET,
                    Order::PAYMENT_METHOD_WALLET_COINS,
                ]);
        };
        $courseQuery = Course::query()
            ->with(['photo', 'category', 'classifications'])
            ->withCount([
                'sections',
                'activeEnrollments',
                'ratings',
                'lessons as preview_steps_count' => fn ($query) => $query->where('is_opened', true),
            ])
            ->withAvg('ratings', 'rating')
            ->whereNull('parent_id');
        if ($canViewFinance) {
            $courseQuery
                ->withSum(['orders as total_coins_spent' => $allocationScope], 'total_coins')
                ->withSum(['orders as paid_coins_spent' => $allocationScope], 'paid_coins')
                ->withSum(['orders as reward_coins_spent' => $allocationScope], 'reward_coins');
        }
        $courses = $courseQuery->get();
        $publishingAudits = $courses->mapWithKeys(function (Course $course) use ($publishingService) {
            return [$course->id => $publishingService->audit($course)];
        });
        $designSettings = $this->getDesignSettings();

        return view('admin.courses.index', compact('courses', 'publishingAudits', 'designSettings', 'canViewFinance'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $classifications = Classification::all();
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        $teachers = User::where('role', 'teacher')->get();
        $paths = Path::all();
        $allowedAiModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        return view('admin.courses.create', compact('enableEnglish', 'classifications', 'levels', 'designSettings', 'teachers', 'paths', 'allowedAiModels'));
    }

    public function exist()
    {
        $courses = Course::with('classifications')->get(['id', 'name_ar', 'description_ar']);
        $coursesXML = '';
        $coursesXML .= '<markers>';

        foreach ($courses as $course) {
            $classification_names = $course->classifications->pluck('name_ar')->implode('، ');
            $coursesXML .= '<marker ';
            $coursesXML .= 'id="' . $course->id . '" ';
            $coursesXML .= 'name="' . $this->parseToXML($course->name_ar) . '" ';
            $coursesXML .= 'address="' . $this->parseToXML($course->description_ar) . '" ';
            $coursesXML .= 'type="' . $this->parseToXML($classification_names) . '" ';
            $coursesXML .= '/>';
        }

        $coursesXML .= '</markers>';

        return view('admin.courses.exist', compact('courses', 'coursesXML'));
    }

    private function parseToXML($htmlStr)
    {
        $xmlStr = str_replace('<', '&lt;', $htmlStr);
        $xmlStr = str_replace('>', '&gt;', $htmlStr);
        $xmlStr = str_replace('"', '&quot;', $htmlStr);
        $xmlStr = str_replace("'", '&#39;', $htmlStr);
        $xmlStr = str_replace('&', '&amp;', $htmlStr);

        return $xmlStr;
    }
    /**
     * Course a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CourseRequest $request, CourseAccessPlanService $accessPlanService)
    {
        $courseData = $this->courseDataForCurrentAdmin($request);
        // A course cannot be complete on its first save because its modules,
        // reels and crossing projects are created on the following screen.
        $courseData['is_coming_soon'] = true;
        $courseData['is_catalog_visible'] = false;
        $course = Course::create($courseData);
        $accessPlanService->createDefaults($course);
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $course->storeImage($file, 'courses', 'featured');
        }

        // Handle classifications relationship
        if($request->classification_ids){
            $course->classifications()->attach($request->classification_ids);
        }
        // Handle teachers relationship
        if($request->teacher_ids){
            $course->teachers()->attach($request->teacher_ids);
        }
        $this->forgetCatalogCache($course);
        return redirect()->route('admin.courses.sections.index', $course->id)
            ->with('success', 'تم حفظ الكورس كمسودة. أكمل الوحدات والخطوات والمشروعات ثم انشره من صفحة التعديل.');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function show(Course $course, CoursePublishingService $publishingService)
    {
        $course->load(['classifications', 'teachers']);
        $sections = $course->sections()->with('sectionable')->orderBy('order')->get();
        $publishingAudit = $publishingService->audit($course);
        $designSettings = $this->getDesignSettings();
        return view('admin.courses.show', compact('course', 'sections', 'publishingAudit', 'designSettings'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function edit(
        Course $course,
        CoursePublishingService $publishingService,
        CourseAccessPlanService $accessPlanService
    )
    {
        $accessPlanService->createDefaults($course);
        $course->load(['classifications', 'teachers', 'accessPlans']);
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $classifications = Classification::all();
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        $teachers = User::where('role', 'teacher')->get();
        $paths = Path::all();
        $allowedAiModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        $publishingAudit = $publishingService->audit($course);
        $planStats = $this->accessPlanStats($course);
        return view('admin.courses.edit', compact('course', 'enableEnglish', 'classifications', 'levels', 'designSettings', 'teachers', 'paths', 'allowedAiModels', 'publishingAudit', 'planStats'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function update(
        CourseRequest $request,
        Course $course,
        CoursePublishingService $publishingService,
        CourseAccessPlanService $accessPlanService
    )
    {
        $previousClassificationIds = $course->classifications()->pluck('classifications.id')->all();
        $wasDraft = (bool) $course->is_coming_soon;
        $publishingRequested = !$request->boolean('is_coming_soon');
        $catalogAnnouncementRequested = $request->boolean('is_catalog_visible');
        $courseData = $this->courseDataForCurrentAdmin($request);
        // Visibility is granted below only after the appropriate audit.
        $courseData['is_catalog_visible'] = false;

        // Save every requested edit, but keep a draft hidden until the readiness
        // audit succeeds. Existing published courses are not unpublished silently.
        if ($wasDraft && $publishingRequested) {
            $courseData['is_coming_soon'] = true;
        }
        DB::transaction(function () use ($course, $courseData, $request, $accessPlanService): void {
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $lockedCourse->update($courseData);
            if ($this->isAdministrator() && $request->has('access_plans')) {
                $accessPlanService->syncAdminPlans(
                    $lockedCourse,
                    (array) $request->input('access_plans', [])
                );
            }

            // Course metadata, commercial terms and dashboard relationships
            // are one admin mutation. Validation or a deadlock rolls all of
            // them back instead of publishing a half-updated product.
            $lockedCourse->classifications()->sync($request->classification_ids ?? []);
            $lockedCourse->teachers()->sync($request->teacher_ids ?? []);
        }, 3);
        $course->refresh();
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $course->replaceImage($file, 'courses', 'featured');
        }

        if ($wasDraft && $publishingRequested) {
            $publishingAudit = $publishingService->audit($course->fresh());
            if (!$publishingAudit['ready']) {
                return redirect()->route('admin.courses.edit', $course)
                    ->with('error', 'تم حفظ التعديلات، لكن الكورس ما زال مسودة حتى تكتمل عناصر النشر.')
                    ->with('publishing_issues', $publishingAudit['issues']);
            }

            $course->update(['is_coming_soon' => false]);
        }

        $freshCourse = $course->fresh();
        if (!$freshCourse->is_coming_soon) {
            $freshCourse->update(['is_catalog_visible' => true]);
        } elseif ($catalogAnnouncementRequested) {
            $catalogAudit = $publishingService->auditCatalogCard($freshCourse);
            if (!$catalogAudit['ready']) {
                return redirect()->route('admin.courses.edit', $course)
                    ->with('error', 'تم حفظ التعديلات، لكن بطاقة قريبًا ما زالت مخفية حتى تكتمل بياناتها.')
                    ->with('publishing_issues', $catalogAudit['issues']);
            }
            $freshCourse->update(['is_catalog_visible' => true]);
        }

        // Serialize hero updates and repair stale flags when the hero changes.
        DB::transaction(function () use ($course, $request): void {
            Course::query()->whereNull('parent_id')->lockForUpdate()->get(['id']);
            $fresh = $course->fresh();
            $targetId = null;
            if (!$fresh->is_coming_soon && $request->boolean('is_main_course')) {
                $targetId = $fresh->id;
            } else {
                $targetId = Course::query()
                    ->whereNull('parent_id')
                    ->where('is_coming_soon', false)
                    ->where('is_main_course', true)
                    ->where('id', '!=', $fresh->is_coming_soon ? $fresh->id : 0)
                    ->value('id');
                $targetId ??= Course::query()
                    ->whereNull('parent_id')
                    ->where('is_coming_soon', false)
                    ->orderByDesc('id')
                    ->value('id');
            }

            Course::query()->whereNull('parent_id')->where('is_main_course', true)
                ->update(['is_main_course' => false]);
            if ($targetId) {
                Course::query()->whereKey($targetId)->update(['is_main_course' => true]);
            }
        }, 3);

        $this->forgetCatalogCache(
            $course->fresh(),
            array_merge(
                $previousClassificationIds,
                $course->classifications()->pluck('classifications.id')->all()
            )
        );

        return redirect()->route('admin.courses.index')->with('success', 'تم التحديث بنجاح ');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function destroy(Course $course)
    {
        abort_unless($this->isAdministrator(), 403);
        $hasCommercialHistory = Order::withTrashed()
            ->where('course_id', $course->id)
            ->exists()
            || CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->exists();
        if ($hasCommercialHistory) {
            return redirect()->route('admin.courses.index')->with(
                'error',
                'لا يمكن حذف كورس له مبيعات أو اشتراكات. أوقف نشره بدلًا من محو السجل المالي.'
            );
        }

        $classificationIds = $course->classifications()->pluck('classifications.id')->all();
        $course->delete();
        $this->forgetCatalogCache($course, $classificationIds);
        return redirect()->route('admin.courses.index')->with('success', 'تم الحذف بنجاح ');
    }

    private function courseDataForCurrentAdmin(CourseRequest $request): array
    {
        $courseData = $request->input();

        if ($this->isAdministrator()) {
            return $courseData;
        }

        foreach (self::PROTECTED_COURSE_FIELDS as $protectedField) {
            unset($courseData[$protectedField]);
        }

        return $courseData;
    }

    private function accessPlanStats(Course $course)
    {
        if (
            !$this->isAdministrator()
            || !Schema::hasColumn('orders', 'access_plan_id')
            || !Schema::hasTable('ai_usage_events')
        ) {
            return collect();
        }

        $sales = Order::query()
            ->where('course_id', $course->id)
            ->where('status', Order::STATUS_APPROVED)
            ->whereNotNull('access_plan_id')
            ->selectRaw('access_plan_id, COUNT(*) as sales_count, COALESCE(SUM(total_coins),0) as total_coins, COALESCE(SUM(paid_coins),0) as paid_coins, COALESCE(SUM(reward_coins),0) as reward_coins')
            ->groupBy('access_plan_id')
            ->get()
            ->keyBy('access_plan_id');
        $usage = DB::table('ai_usage_events')
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->whereNotNull('access_plan_id')
            ->selectRaw('access_plan_id, feature, COUNT(*) as ai_requests, COALESCE(SUM(total_tokens),0) as total_tokens, COALESCE(SUM(cost_usd),0) as cost_usd')
            ->groupBy('access_plan_id', 'feature')
            ->get()
            ->keyBy(fn ($row) => $row->access_plan_id . ':' . $row->feature);

        return $course->accessPlans->mapWithKeys(fn ($plan) => [
            $plan->code => [
                'sales_count' => (int) ($sales->get($plan->id)?->sales_count ?? 0),
                'total_coins' => (int) ($sales->get($plan->id)?->total_coins ?? 0),
                'paid_coins' => (int) ($sales->get($plan->id)?->paid_coins ?? 0),
                'reward_coins' => (int) ($sales->get($plan->id)?->reward_coins ?? 0),
                'chat_requests' => (int) ($usage->get($plan->id . ':course_chat')?->ai_requests ?? 0),
                'chat_tokens' => (int) ($usage->get($plan->id . ':course_chat')?->total_tokens ?? 0),
                'chat_cost_usd' => (float) ($usage->get($plan->id . ':course_chat')?->cost_usd ?? 0),
                'project_requests' => (int) ($usage->get($plan->id . ':project_feedback')?->ai_requests ?? 0),
                'project_tokens' => (int) ($usage->get($plan->id . ':project_feedback')?->total_tokens ?? 0),
                'project_cost_usd' => (float) ($usage->get($plan->id . ':project_feedback')?->cost_usd ?? 0),
            ],
        ]);
    }

    /** @param list<int|string> $additionalClassificationIds */
    private function forgetCatalogCache(Course $course, array $additionalClassificationIds = []): void
    {
        try {
            $catalogRevisionKey = 'courses:catalog-revision';
            Cache::forever(
                $catalogRevisionKey,
                max(1, (int) Cache::get($catalogRevisionKey, 1)) + 1
            );
            Cache::forget('home:courses:v4');
            Cache::forget('mobile-home:main-courses:v3');
            Cache::forget('mobile-home:classifications:v4');
            Cache::forget('mobile-home:classifications:v5');
            Cache::forget('mobile-home:classifications:v6:managed');
            Cache::forget('mobile-home:classifications:v6:legacy');

            $classificationIds = array_unique(array_map(
                'intval',
                array_merge(
                    $additionalClassificationIds,
                    $course->exists
                        ? $course->classifications()->pluck('classifications.id')->all()
                        : []
                )
            ));
            foreach ($classificationIds as $classificationId) {
                Cache::forget("mobile-home:classification:{$classificationId}:courses:v4");
                Cache::forget("mobile-home:classification:{$classificationId}:courses:v5");
                Cache::forget("mobile-home:classification:{$classificationId}:courses:v6:managed");
                Cache::forget("mobile-home:classification:{$classificationId}:courses:v6:legacy");
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function isAdministrator(): bool
    {
        return strtolower((string) optional(auth()->user())->role) === 'admin';
    }
}
