<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\CourseCode;
use App\Models\CourseRating;
use App\Models\DesignSetting;
use App\Models\Level;
use App\Models\Order;
use App\Models\Path;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\ArabicSearchNormalizer;
use App\Services\CertificateService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\CourseCommercialReportService;
use App\Services\CourseDurationService;
use App\Services\CourseHeroSelectionService;
use App\Services\CourseLearningHealthService;
use App\Services\CoursePresentationService;
use App\Services\CourseReadCompatibilityService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use App\Services\StoredFileDeletionService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Support\CsvCell;

class CourseController extends Controller
{
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
    public function index(
        Request $request,
        CoursePublishingService $publishingService,
        ArabicSearchNormalizer $searchNormalizer
    )
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'classification_id' => 'nullable|integer|exists:classifications,id',
            'state' => 'nullable|string|in:active,archived,all',
        ]);
        $canViewFinance = $this->isAdministrator();
        $filters['state'] = $canViewFinance
            ? (string) ($filters['state'] ?? 'active')
            : 'active';
        $allocationScope = function ($query): void {
            $query->financiallyEffective()
                ->whereIn('payment_method', [
                    Order::PAYMENT_METHOD_WALLET,
                    Order::PAYMENT_METHOD_WALLET_COINS,
                ]);
        };
        $courseQuery = ($canViewFinance && $filters['state'] !== 'active'
                ? Course::withTrashed()
                : Course::query())
            ->with(['photo', 'classifications', 'accessPlans'])
            ->withCount([
                'sections',
                'lessons as preview_steps_count' => fn ($query) => $query->where('is_opened', true),
            ])
            ->whereNotIn('courses.id', CourseAuthoringRevision::query()->select('revision_course_id'))
            ->whereNull('parent_id');
        if ($filters['state'] === 'active') {
            // Keep authoring drafts and discoverable products in the daily
            // list. A retired published course belongs in the archive view
            // even though its row remains live for existing entitlements.
            $courseQuery->where(function ($current): void {
                $current->where('is_coming_soon', true)
                    ->orWhere('is_catalog_visible', true);
            });
        } elseif ($canViewFinance && $filters['state'] === 'archived') {
            $courseQuery->where(function ($archived): void {
                $archived->whereNotNull('courses.deleted_at')
                    ->orWhere(function ($retired): void {
                        $retired->whereNull('courses.deleted_at')
                            ->where('is_coming_soon', false)
                            ->where('is_catalog_visible', false);
                    });
            });
        }
        if (!empty($filters['search'])) {
            $rawSearch = trim((string) $filters['search']);
            $literalSearch = addcslashes($rawSearch, '\\%_');
            $normalizedSearch = $searchNormalizer->normalize($rawSearch);
            $courseQuery->where(function ($search) use (
                $literalSearch,
                $normalizedSearch
            ): void {
                $search->where('name_ar', 'like', "%{$literalSearch}%")
                    ->orWhere('name_en', 'like', "%{$literalSearch}%")
                    ->orWhere('description_ar', 'like', "%{$literalSearch}%")
                    ->orWhere('description_en', 'like', "%{$literalSearch}%");
                if (
                    $normalizedSearch !== ''
                    && Schema::hasColumn('courses', 'search_terms_normalized')
                ) {
                    $search->orWhere(
                        'search_terms_normalized',
                        'like',
                        '%' . addcslashes($normalizedSearch, '\\%_') . '%'
                    );
                    $tokens = array_values(array_unique(array_filter(
                        explode(' ', $normalizedSearch),
                        fn (string $token): bool => mb_strlen($token) >= 2
                    )));
                    if ($tokens !== []) {
                        $search->orWhere(function ($allTokens) use ($tokens): void {
                            foreach ($tokens as $token) {
                                $allTokens->where(
                                    'search_terms_normalized',
                                    'like',
                                    '%' . addcslashes($token, '\\%_') . '%'
                                );
                            }
                        });
                    }
                }
            });
        }
        if (!empty($filters['classification_id'])) {
            $courseQuery->whereHas('classifications', fn ($classifications) =>
                $classifications->whereKey((int) $filters['classification_id'])
            );
        }
        if ($canViewFinance) {
            $courseQuery
                ->withCount(['activeEnrollments', 'ratings'])
                ->withAvg('ratings', 'rating')
                ->withSum(['orders as total_coins_spent' => $allocationScope], 'total_coins')
                ->withSum(['orders as paid_coins_spent' => $allocationScope], 'paid_coins')
                ->withSum(['orders as reward_coins_spent' => $allocationScope], 'reward_coins');
        }
        $courses = $courseQuery
            ->latest('updated_at')
            ->latest('id')
            ->paginate(24)
            ->withQueryString();
        $publishingAudits = $courses->getCollection()->mapWithKeys(
            fn (Course $course) => [
                $course->id => $course->trashed()
                    ? null
                    : $publishingService->audit($course),
            ]
        );
        $classificationOptions = Classification::query()
            ->orderBy('home_order')
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get(['id', 'name_ar', 'name_en']);
        $designSettings = $this->getDesignSettings();

        return view('admin.courses.index', compact(
            'courses',
            'publishingAudits',
            'designSettings',
            'canViewFinance',
            'classificationOptions',
            'filters'
        ));
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
        $classifications = Classification::query()->orderBy('home_order')->orderBy('id')->get();
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        $teachers = User::where('role', 'teacher')->orderBy('name_ar')->orderBy('id')->get();
        $paths = Path::query()->orderBy('title_ar')->orderBy('id')->get();
        $allowedAiModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        $certificateTextTemplates = (array) config('certificate.text_templates', []);
        return view('admin.courses.create', compact('enableEnglish', 'classifications', 'levels', 'designSettings', 'teachers', 'paths', 'allowedAiModels', 'certificateTextTemplates'));
    }

    /**
     * Course a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(
        CourseRequest $request,
        CourseAccessPlanService $accessPlanService,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $requestId = (string) $request->validated('authoring_request_id');
        $existing = Course::query()->where('authoring_request_id', $requestId)->first();
        if ($existing) {
            DB::transaction(function () use ($request, $existing, $createIntents): void {
                Course::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                $createIntents->completeRedirect(
                    $request,
                    route('admin.courses.show', $existing),
                    302,
                    Course::class,
                    $existing->id
                );
            }, 3);
            return redirect()->route('admin.courses.show', $existing)
                ->with('success', 'تم حفظ الكورس بالفعل');
        }
        $courseData = $this->courseDataForCurrentAdmin($request);
        $courseData['authoring_request_id'] = $requestId;
        // A course cannot be complete on its first save because its modules,
        // reels and crossing projects are created on the following screen.
        $courseData['is_coming_soon'] = true;
        $courseData['is_catalog_visible'] = false;
        $storedImagePath = null;
        try {
            if ($request->hasFile('image')) {
                $storedImagePath = app(StoredFileDeletionService::class)
                    ->storeTrackedUpload($request->file('image'), 'courses');
                if (!is_string($storedImagePath) || trim($storedImagePath) === '') {
                    throw new \RuntimeException('Course image storage failed');
                }
            }

            $course = DB::transaction(function () use (
                $courseData,
                $request,
                $accessPlanService,
                $storedImagePath,
                $createIntents
            ): Course {
                $course = Course::create($courseData);
                $accessPlanService->createDefaults($course);
                $course->classifications()->sync($request->classification_ids ?? []);
                $course->teachers()->sync($request->teacher_ids ?? []);
                if ($storedImagePath) {
                    $course->allPhotos()->create([
                        'path' => $storedImagePath,
                        'type' => 'featured',
                    ]);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.courses.show', $course),
                    302,
                    Course::class,
                    $course->id
                );

                return $course;
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedImagePath) {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $storedImagePath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $existing = Course::query()->where('authoring_request_id', $requestId)->first();
            if ($existing) {
                return redirect()->route('admin.courses.show', $existing)
                    ->with('success', 'تم حفظ الكورس بالفعل');
            }
            report($exception);

            return redirect()->back()
                ->withInput()
                ->with('error', 'تعذر حفظ الكورس الآن');
        }
        $this->forgetCatalogCache($course);
        return redirect()->route('admin.courses.show', $course)
            ->with('success', 'تم حفظ الكورس كمسودة. أضف الوحدات والمقاطع، وأضف مشاريع العبور فقط حيث يحتاج المحتوى إليها.');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function show(
        Course $course,
        CoursePublishingService $publishingService,
        CourseCommercialReportService $commercialReports,
        CourseDurationService $durations,
        CourseAccessPlanService $accessPlanService,
        CourseLearningHealthService $learningHealth,
        CourseStagedAuthoringService $stagedAuthoring
    )
    {
        $course->load([
            'classifications',
            'teachers',
            'level',
            'photo',
            'accessPlans',
            'modules' => fn ($query) => $query->with([
                'sections' => fn ($sections) => $sections->with('sectionable')->orderBy('order'),
            ])->orderBy('order'),
        ]);
        $sections = $course->sections()->with('sectionable')->orderBy('order')->get();
        $course->setRelation('sections', $sections);
        $reportCourse = $stagedAuthoring->canonicalFor($course);
        $reportCourse->loadCount(['activeEnrollments', 'ratings']);
        $reportCourse->loadAvg('ratings', 'rating');
        $durations->attach($course);
        $ungroupedSections = $sections->whereNull('module_id')->values();
        $publishingAudit = $publishingService->audit($course);
        $previewPlans = $course->accessPlans
            ->where('is_active', true)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn ($plan): array => $accessPlanService->publicPayload($plan))
            ->values();
        $designSettings = $this->getDesignSettings();
        $commercialReport = $this->isAdministrator()
            ? $commercialReports->forCourse($reportCourse)
            : null;
        // These two aggregates are the same real public social proof shown to
        // learners. Financial identities and cash remain administrator-only.
        $activeStudentsCount = (int) $reportCourse->active_enrollments_count;
        $learningHealthSummary = $learningHealth->forCourse($reportCourse);
        $ratingSummary = $this->isAdministrator() ? [
            'count' => (int) $reportCourse->ratings_count,
            'average' => $reportCourse->ratings_count > 0
                ? round((float) $reportCourse->ratings_avg_rating, 1)
                : null,
            'removed_count' => CourseRating::onlyTrashed()
                ->where('course_id', $reportCourse->id)
                ->count(),
        ] : null;
        return view('admin.courses.show', compact(
            'course',
            'sections',
            'ungroupedSections',
            'publishingAudit',
            'designSettings',
            'commercialReport',
            'activeStudentsCount',
            'learningHealthSummary',
            'ratingSummary',
            'previewPlans'
        ));
    }

    /** Exact entitled learner contract, including draft rows, behind dashboard auth. */
    public function studentPreview(
        Request $request,
        Course $course,
        CourseReadCompatibilityService $courseReads,
        CoursePresentationService $presentation,
        CourseAccessPlanService $plans,
        CertificateService $certificates
    ) {
        $validated = $request->validate([
            'plan' => 'nullable|string|max:32',
        ]);
        $previewCourse = $courseReads->detailedCourseForAdminPreview((int) $course->id);
        $planOptions = $plans->publicPlans($previewCourse)
            ->map(fn ($plan): array => $plans->publicPayload($plan))
            ->values();
        $hasInstitutionalGrant = CourseCode::query()
            ->where('course_id', $previewCourse->id)
            ->valid()
            ->get()
            ->contains(fn (CourseCode $code): bool => $code->isInstitutionalGrant());

        if ($hasInstitutionalGrant) {
            $planOptions->push([
                'code' => 'grant',
                'name' => 'منحة جهة تعليمية',
                'price_coins' => 0,
                'minimum_paid_coins' => 0,
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_attachments_enabled' => false,
                'chat_attachment_max_files' => 0,
                'project_feedback_level' => 'pass_only',
                'project_report_enabled' => false,
                'project_thread_reply_enabled' => false,
                'project_message_limit' => 0,
                'project_token_budget' => 0,
                'project_attachments_enabled' => false,
                'project_attachment_max_files' => 0,
                'project_output_enabled' => false,
                'certificate_enabled' => false,
            ]);
        }

        abort_if($planOptions->isEmpty(), 422, 'أنشئ فئة نشطة قبل معاينة تجربة الطالب.');
        $requestedCode = strtolower(trim((string) ($validated['plan'] ?? '')));
        $selectedPlan = $requestedCode !== ''
            ? $planOptions->firstWhere('code', $requestedCode)
            : ($planOptions->firstWhere('code', \App\Models\CourseAccessPlan::BASIC)
                ?? $planOptions->first());
        abort_unless($selectedPlan, 422, 'هذه الفئة لم تعد متاحة للمعاينة.');

        /** @var User $actor */
        $actor = $request->user();
        $accessType = $selectedPlan['code'] === 'grant'
            ? 'scholarship'
            : 'paid';
        $previewPayload = $presentation
            ->dashboardPreview($previewCourse, $actor, $selectedPlan, $accessType)
            ->resolve($request);
        $certificateTextTemplate = $certificates
            ->selectedTextTemplateForCourse($previewCourse);
        abort_unless(
            $certificateTextTemplate !== null,
            422,
            'اختر صياغة شهادة صالحة قبل معاينة تجربة الطالب.'
        );

        return response()->view('admin.courses.student-preview', compact(
            'previewCourse',
            'previewPayload',
            'planOptions',
            'selectedPlan',
            'certificateTextTemplate'
        ))->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /** Download the auditable learner economics ledger without exposing it to moderators. */
    public function exportCommercialReport(
        Course $course,
        CourseCommercialReportService $commercialReports,
        CourseStagedAuthoringService $stagedAuthoring
    ) {
        abort_unless($this->isAdministrator(), 403);
        // The studio may be displaying an isolated draft id. Financial facts
        // always belong to the canonical learner-facing commercial shell.
        $reportCourse = $stagedAuthoring->canonicalFor($course);
        $report = $commercialReports->forCourse($reportCourse);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'الطالب', 'البريد', 'الحالة', 'مصدر الإتاحة', 'الفئة', 'سعر العقد بالعملات',
                'إجمالي العملات', 'خصم العملات', 'أكواد الخصم', 'أكواد الإتاحة والمنح', 'عملات مشتراة', 'عملات مكافآت', 'إجمالي نقدي مؤكد منسوب',
                'إجمالي تقديري بسعر الكتالوج', 'حالة الإجمالي',
                'قنوات الشحن', 'صافي بوابات الدفع', 'حالة التسوية', 'طلبات AI', 'طلبات AI فاشلة',
                'طلبات AI بتكلفة تقديرية', 'حالة تكلفة AI', 'توكنات AI',
                'تكلفة AI بالدولار', 'دقائق المشاهدة', 'GB مشاهدة مقدرة',
                'تكلفة الخدمات الفعلية بالجنيه', 'التكلفة شاملة التقديرات', 'هامش المساهمة الفعلي',
                'هامش المساهمة التقديري', 'نسبة التكلفة من الصافي', 'نسبة هامش المساهمة',
                ...array_map(
                    fn (string $label): string => "تكلفة {$label}",
                    \App\Services\CourseCostReportService::serviceLabels()
                ),
            ], ',', '"', '');
            foreach ($report['rows'] as $row) {
                fputcsv($output, CsvCell::row([
                    $row['user']?->name ?? 'مستخدم محذوف', $row['user']?->email,
                    $row['is_active'] ? 'نشط' : 'غير نشط', $row['source_label'], $row['plan_name'],
                    $row['contract_price_coins'], $row['total_coins'], $row['discount_coins'],
                    implode(' | ', $row['coupon_codes']),
                    implode(' | ', $row['access_codes']), $row['paid_coins'],
                    $row['reward_coins'], $row['cash_gross_egp'],
                    $row['cash_estimated_gross_egp'],
                    $row['cash_gross_complete'] ? 'مؤكد' : 'جزئي أو تقديري',
                    collect($row['cash_channels'])->map(
                        fn (array $channel): string => $channel['label'].' ('.number_format($channel['paid_coins']).' عملة)'
                    )->implode(' | '),
                    $row['cash_net_known_egp'],
                    $row['cash_net_complete'] ? 'مكتملة' : 'غير مكتملة', $row['ai_requests'],
                    $row['ai_failed_requests'], $row['ai_estimated_requests'],
                    $row['ai_cost_complete'] ? 'مؤكدة من المزود' : 'تتضمن تقديرات',
                    $row['ai_tokens'], $row['ai_cost_usd'],
                    $row['playback_minutes'], $row['playback_gb_estimated'],
                    $row['service_cost_actual_egp'], $row['service_cost_with_estimates_egp'],
                    $row['contribution_margin_egp'], $row['estimated_contribution_margin_egp'],
                    $row['cost_to_net_revenue_percentage'], $row['contribution_margin_percentage'],
                    ...array_map(
                        fn (string $key) => $row['actual_cost_by_service_egp'][$key] ?? null,
                        array_keys(\App\Services\CourseCostReportService::serviceLabels())
                    ),
                ]), ',', '"', '');
            }
            fclose($output);
        }, "course-{$reportCourse->id}-commercial-report.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
        CourseAccessPlanService $accessPlanService,
        CourseAuthoringConcurrencyService $authoring,
        CourseStagedAuthoringService $stagedAuthoring
    )
    {
        $course->load(['classifications', 'teachers']);
        $course->setRelation(
            'accessPlans',
            $accessPlanService->plansForEditor($course)
        );
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $classifications = Classification::query()->orderBy('home_order')->orderBy('id')->get();
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        $teachers = User::where('role', 'teacher')->orderBy('name_ar')->orderBy('id')->get();
        $paths = Path::query()->orderBy('title_ar')->orderBy('id')->get();
        $allowedAiModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        $certificateTextTemplates = (array) config('certificate.text_templates', []);
        $publishingAudit = $publishingService->audit($course);
        // Moderators author the three product tiers, but actual sales, wallet
        // composition and provider cost belong to the administrator. Keeping
        // the report out of one tab is not enough if the same figures are
        // injected into the edit form.
        $planStats = $this->isAdministrator()
            ? $this->accessPlanStats($stagedAuthoring->canonicalFor($course))
            : collect();
        return view('admin.courses.edit', compact('course', 'enableEnglish', 'classifications', 'levels', 'designSettings', 'teachers', 'paths', 'allowedAiModels', 'certificateTextTemplates', 'publishingAudit', 'planStats'));
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
        CourseAccessPlanService $accessPlanService,
        CourseAuthoringConcurrencyService $authoring,
        CourseHeroSelectionService $heroSelection,
        CourseStagedAuthoringService $stagedAuthoring
    )
    {
        $previousClassificationIds = $course->classifications()->pluck('classifications.id')->all();
        $wasDraft = (bool) $course->is_coming_soon;
        $publishingRequested = !$request->boolean('is_coming_soon');
        $catalogAnnouncementRequested = $request->has('is_catalog_visible')
            ? $request->boolean('is_catalog_visible')
            : (bool) $course->is_catalog_visible;
        $courseData = $this->courseDataForCurrentAdmin($request);
        // Visibility is granted below only after the appropriate audit.
        // Draft publication and coming-soon announcements are granted only
        // after their audits below. An already published course may be
        // unlisted without revoking access from its existing learners.
        $courseData['is_catalog_visible'] = !$wasDraft
            && $publishingRequested
            && $catalogAnnouncementRequested;

        // Save every requested edit, but keep a draft hidden until the readiness
        // audit succeeds. Existing published courses are not unpublished silently.
        if ($wasDraft && $publishingRequested) {
            $courseData['is_coming_soon'] = true;
        }
        $storedImagePath = null;
        $oldPhotoIds = collect();
        $livePublishingIssues = [];
        $ownedAuthoringVersion = null;
        $managedDraft = $stagedAuthoring->isManagedDraft($course);
        try {
            if ($request->hasFile('image')) {
                $storedImagePath = app(StoredFileDeletionService::class)
                    ->storeTrackedUpload($request->file('image'), 'courses');
                if (!is_string($storedImagePath) || trim($storedImagePath) === '') {
                    throw new \RuntimeException('Course image storage failed');
                }
                $oldPhotoIds = $course->allPhotos()
                    ->where('type', 'featured')
                    ->pluck('photos.id');
            }

            DB::transaction(function () use (
                $course,
                $courseData,
                $request,
                $accessPlanService,
                $storedImagePath,
                $publishingService,
                $publishingRequested,
                $wasDraft,
                $authoring,
                $managedDraft,
                $oldPhotoIds,
                &$livePublishingIssues,
                &$ownedAuthoringVersion
            ): void {
                $lockedCourse = $authoring->lock($request, $course);
                $lockedCourse->update($courseData);
                if ($managedDraft && $request->has('is_main_course')) {
                    // This is draft intent only. Global hero ownership changes
                    // atomically when the revision is published.
                    $lockedCourse->updateQuietly([
                        'is_main_course' => $request->boolean('is_main_course'),
                    ]);
                }
                if ($this->canManageCourses() && $request->has('access_plans')) {
                    $accessPlanService->syncAdminPlans(
                        $lockedCourse,
                        (array) $request->input('access_plans', []),
                        $this->isAdministrator()
                    );
                }
                  if (!$managedDraft && $this->isAdministrator() && (
                      $request->boolean('grant_chat_attachments_to_current_enrollments')
                      || $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                  )) {
                    $accessPlanService->grantAttachmentsToCurrentEnrollments(
                        $lockedCourse,
                        $request->boolean('grant_chat_attachments_to_current_enrollments'),
                        $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                    );
                }

                // Course metadata, commercial terms and dashboard relationships
                // are one admin mutation. Validation or a deadlock rolls all of
                // them back instead of publishing a half-updated product.
                $lockedCourse->classifications()->sync($request->classification_ids ?? []);
                $lockedCourse->teachers()->sync($request->teacher_ids ?? []);
                if ($storedImagePath) {
                    $lockedCourse->allPhotos()->create([
                        'path' => $storedImagePath,
                        'type' => 'featured',
                    ]);
                    Photo::query()->whereIn('id', $oldPhotoIds)
                        ->lockForUpdate()->get()->each->delete();
                }

                // A live course is an active product contract. Never commit a
                // metadata or pricing edit that would leave its learner-facing
                // page unplayable. Explicitly moving it back to draft remains
                // available for larger rebuilds.
                if (!$wasDraft && $publishingRequested) {
                    $liveAudit = $publishingService->audit($lockedCourse->fresh());
                    if (!$liveAudit['ready']) {
                        $livePublishingIssues = $liveAudit['issues'];
                        throw new \DomainException('published_course_incomplete');
                    }
                }

                $ownedAuthoringVersion = $authoring->advance($lockedCourse);
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedImagePath) {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $storedImagePath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            if (
                $exception instanceof \DomainException
                && $exception->getMessage() === 'published_course_incomplete'
            ) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'لم نحفظ التعديل لأن الكورس المنشور يجب أن يظل مكتملًا')
                    ->with('publishing_issues', $livePublishingIssues);
            }
            report($exception);

            return redirect()->back()
                ->withInput()
                ->with('error', 'تعذر حفظ تعديلات الكورس الآن');
        }

        $course->refresh();

        if ($wasDraft && $publishingRequested && $managedDraft) {
            try {
                $published = $stagedAuthoring->publish(
                    $course,
                    (int) $ownedAuthoringVersion,
                    $catalogAnnouncementRequested,
                    $this->isAdministrator()
                        && $request->boolean('grant_chat_attachments_to_current_enrollments'),
                    $this->isAdministrator()
                        && $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                );
                $course = $published['course'];
                $ownedAuthoringVersion = (int) $published['published_revision'];
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                report($exception);
                return redirect()->route('admin.courses.edit', $course)
                    ->with('success', 'تم حفظ المسودة')
                    ->with('error', 'لم يكتمل النشر\nالنسخة الحالية ما زالت متاحة للطلاب');
            }
        } elseif ($wasDraft && $publishingRequested) {
            $publishingAudit = null;
            $publishedRevision = null;
            try {
                DB::transaction(function () use (
                    $course,
                    $catalogAnnouncementRequested,
                    $publishingService,
                    $authoring,
                    &$publishingAudit,
                    &$publishedRevision,
                    &$ownedAuthoringVersion
                ): void {
                    $lockedCourse = $authoring->lockExpected($course, (int) $ownedAuthoringVersion);
                    $publishingAudit = $publishingService->audit($lockedCourse->fresh());
                    if ($publishingAudit['ready']) {
                        $previousPublishedRevision = (int) ($lockedCourse->last_published_authoring_version ?? 0);
                        $lockedCourse->update([
                            'is_coming_soon' => false,
                            'is_catalog_visible' => $catalogAnnouncementRequested,
                        ]);
                        $publishedRevision = $authoring->advance($lockedCourse);
                        $ownedAuthoringVersion = $publishedRevision;
                        $lockedCourse->forceFill([
                            'last_published_authoring_version' => $publishedRevision,
                            'published_at' => now(),
                        ])->save();
                        if ($previousPublishedRevision > 0
                            && $publishedRevision > $previousPublishedRevision) {
                            // Persist the campaign in the same transaction as the
                            // published revision. The coordinator is dispatched
                            // after commit, so a killed HTTP request cannot publish
                            // content while silently losing its one notification.
                            NotificationService::notifyCourseUpdate(
                                $lockedCourse->fresh(),
                                'published_changes',
                                'course-published:' . $lockedCourse->id . ':v' . $publishedRevision
                            );
                        } elseif ($previousPublishedRevision === 0 && $catalogAnnouncementRequested) {
                            NotificationService::notifyNewCourse(
                                $lockedCourse->fresh(),
                                'course-published:' . $lockedCourse->id . ':v' . $publishedRevision . ':new'
                            );
                        }
                    }
                }, 3);
            } catch (\Throwable $exception) {
                report($exception);
                return redirect()->route('admin.courses.edit', $course)
                    ->with('success', 'تم حفظ تعديلات الكورس')
                    ->with('error', 'لم يكتمل النشر\nأعد تحميل الصفحة وراجعه قبل المحاولة');
            }
            if (!$publishingAudit['ready']) {
                return redirect()->route('admin.courses.edit', $course)
                    ->with('error', 'تم حفظ التعديلات، لكن الكورس ما زال مسودة حتى تكتمل عناصر النشر.')
                    ->with('publishing_issues', $publishingAudit['issues']);
            }
            $course->refresh();
        }

        $freshCourse = $course->fresh();
        if (!$managedDraft && $freshCourse->is_coming_soon && $catalogAnnouncementRequested) {
            $catalogAudit = null;
            try {
                DB::transaction(function () use (
                    $freshCourse,
                    $publishingService,
                    $authoring,
                    &$catalogAudit,
                    &$ownedAuthoringVersion
                ): void {
                    $lockedCourse = $authoring->lockExpected($freshCourse, (int) $ownedAuthoringVersion);
                    $catalogAudit = $publishingService->auditCatalogCard($lockedCourse->fresh());
                    if ($catalogAudit['ready']) {
                        $lockedCourse->update(['is_catalog_visible' => true]);
                        $ownedAuthoringVersion = $authoring->advance($lockedCourse);
                    }
                }, 3);
            } catch (\Throwable $exception) {
                report($exception);
                return redirect()->route('admin.courses.edit', $course)
                    ->with('success', 'تم حفظ تعديلات الكورس')
                    ->with('error', 'لم يكتمل إظهار بطاقة الكورس\nأعد تحميل الصفحة ثم حاول مرة أخرى');
            }
            if (!$catalogAudit['ready']) {
                return redirect()->route('admin.courses.edit', $course)
                    ->with('error', 'تم حفظ التعديلات، لكن بطاقة قريبًا ما زالت مخفية حتى تكتمل بياناتها.')
                    ->with('publishing_issues', $catalogAudit['issues']);
            }
        }

        // The public hero is one aggregate, not an ordinary course field. It
        // is applied only after the draft/publish decision and under the same
        // locked, revision-checked operation for every root course.
        $heroCourse = $course->fresh();
        $heroRequestedMain = $request->has('is_main_course')
            ? $request->boolean('is_main_course')
            : (bool) $heroCourse->is_main_course;
        try {
            if (!($managedDraft && !$publishingRequested)) {
                $heroSelection->synchronize(
                    $course,
                    (int) $ownedAuthoringVersion,
                    $heroRequestedMain
                );
            }
        } catch (\Throwable $exception) {
            // Metadata/publishing is already committed. The hero operation is
            // idempotent and independently revision-checked, so report its
            // real outcome instead of returning a generic failure that invites
            // the moderator to resubmit the whole stale course form.
            report($exception);
            $this->forgetCatalogCache($course->fresh());
            return redirect()->route('admin.courses.edit', $course)
                ->with('success', 'تم حفظ تعديلات الكورس')
                ->with('error', 'لم يتغير اختيار الواجهة الرئيسية\nأعد تحميل الصفحة ثم حاول مرة أخرى');
        }

        $this->forgetCatalogCache(
            $course->fresh(),
            array_merge(
                $previousClassificationIds,
                $course->classifications()->pluck('classifications.id')->all()
            )
        );

        $destination = $request->input('return_to') === 'studio'
            ? 'admin.courses.show'
            : 'admin.courses.index';

        return redirect()->route(
            $destination,
            $destination === 'admin.courses.show' ? $course : []
        )->with('success', 'تم تحديث الكورس بنجاح.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Course  $course
     * @return \Illuminate\Http\Response
     */
    public function destroy(
        Request $request,
        Course $course
    )
    {
        abort_unless($this->isAdministrator(), 403);
        $validated = $request->validate([
            'authoring_version' => 'required|integer|min:1',
        ]);
        $classificationIds = [];
        $unlisted = false;
        $discardedDraft = false;
        DB::transaction(function () use (
            $course,
            $validated,
            &$classificationIds,
            &$unlisted,
            &$discardedDraft
        ): void {
            // Administrative retirement is not a draft edit. Lock the
            // canonical product directly; the authoring lock deliberately
            // rejects published rows and made the archive button unusable on
            // the courses that actually need this lifecycle.
            $lockedCourse = Course::query()->whereKey($course->id)
                ->lockForUpdate()->firstOrFail();
            if ((int) $lockedCourse->authoring_version !== (int) $validated['authoring_version']) {
                throw ValidationException::withMessages([
                    'authoring_version' => [
                        'تغيّر الكورس منذ فتح الصفحة\nأعد تحميلها قبل الأرشفة',
                    ],
                ])->status(409);
            }
            $classificationIds = $lockedCourse->classifications()
                ->pluck('classifications.id')->all();

            $wasEverPublished = $lockedCourse->published_at !== null
                || (int) ($lockedCourse->last_published_authoring_version ?? 0) > 0
                || !(bool) $lockedCourse->is_coming_soon;
            $hasCommercialHistory = $lockedCourse->enrollments()->exists()
                || $lockedCourse->orders()->exists();

            if ($wasEverPublished || $hasCommercialHistory) {
                // A purchased course is an entitlement, not a catalogue row.
                // Unlisting blocks every new checkout while retaining the
                // immutable content/plan snapshots for existing learners.
                $lockedCourse->forceFill([
                    'is_catalog_visible' => false,
                    'is_main_course' => false,
                    'authoring_version' => (int) $lockedCourse->authoring_version + 1,
                ])->save();
                $unlisted = true;

                // A moderator tab cloned before this retirement must not be
                // able to publish later and silently relist the product.
                $activeDraft = CourseAuthoringRevision::query()
                    ->where('canonical_course_id', $lockedCourse->id)
                    ->where('status', CourseAuthoringRevision::DRAFT)
                    ->lockForUpdate()
                    ->first();
                if ($activeDraft) {
                    $activeDraft->forceFill([
                        'status' => CourseAuthoringRevision::ARCHIVED,
                        'active_slot' => null,
                        'retain_until' => now()->addDays(
                            max(7, (int) config('playback.revision_grace_days', 7))
                        ),
                    ])->save();
                    $discardedDraft = true;
                }
                return;
            }

            // A never-published draft has no learner or financial contract to
            // preserve and can enter the recoverable soft-delete archive.
            $lockedCourse->delete();
        }, 3);

        $this->forgetCatalogCache($course, $classificationIds);
        if ($unlisted) {
            return redirect()->route('admin.courses.index')->with(
                'success',
                $discardedDraft
                    ? 'أُخفي الكورس من الكتالوج مع استمرار وصول الطلاب وأُغلقت المسودة القديمة'
                    : 'أُخفي الكورس من الكتالوج مع استمرار وصول الطلاب الحاليين'
            );
        }

        return redirect()->route('admin.courses.index')->with(
            'success',
            'نُقلت المسودة غير المنشورة إلى الأرشيف'
        );
    }

    public function restore(Request $request, int $courseId)
    {
        abort_unless($this->isAdministrator(), 403);

        $preservedLearnerAccess = false;
        $course = DB::transaction(function () use (
            $courseId,
            &$preservedLearnerAccess
        ): Course {
            $course = Course::onlyTrashed()
                ->whereKey($courseId)
                ->lockForUpdate()
                ->firstOrFail();
            $preservedLearnerAccess = CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->active()
                ->exists();
            $course->restore();
            // Never relist a restored commercial page. Legacy rows that were
            // archived before retirement became non-destructive may still
            // have active paid/grant enrollments; restore their private
            // learning access without reopening checkout.
            $course->forceFill([
                'is_catalog_visible' => false,
                'is_coming_soon' => !$preservedLearnerAccess,
                'is_main_course' => false,
                'authoring_version' => max(1, (int) $course->authoring_version + 1),
            ])->save();

            return $course;
        }, 3);

        $this->forgetCatalogCache($course);

        return redirect()->route('admin.courses.edit', $course)->with(
            'success',
            $preservedLearnerAccess
                ? 'استُعيد الكورس مخفيًا من الكتالوج وعاد وصول الطلاب الحاليين'
                : 'استُعيد الكورس كمسودة مخفية للمراجعة قبل النشر'
        );
    }

    private function courseDataForCurrentAdmin(CourseRequest $request): array
    {
        return collect($request->validated())->except([
            'image',
            'classification_ids',
            'teacher_ids',
            'access_plans',
            'authoring_version',
            'authoring_request_id',
            'grant_chat_attachments_to_current_enrollments',
            'grant_project_followup_attachments_to_current_enrollments',
            'is_main_course',
        ])->all();
    }

    private function accessPlanStats(Course $course)
    {
        if (
            !$this->isAdministrator()
            || !Schema::hasColumn('orders', 'access_plan_id')
            || !Schema::hasTable('course_access_plans')
            || !Schema::hasTable('ai_usage_events')
        ) {
            return collect();
        }

        $driver = DB::connection()->getDriverName();
        $snapshotPlanCode = Schema::hasColumn('orders', 'access_plan_snapshot')
            ? match ($driver) {
                'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(orders.access_plan_snapshot, '$.code'))",
                'pgsql' => "orders.access_plan_snapshot->>'code'",
                'sqlite' => "json_extract(orders.access_plan_snapshot, '$.code')",
                default => 'NULL',
            }
            : 'NULL';
        $salesPlanCode = "COALESCE(NULLIF({$snapshotPlanCode}, ''), historical_plan.code)";
        $sales = Order::query()
            ->leftJoin(
                'course_access_plans as historical_plan',
                'historical_plan.id',
                '=',
                'orders.access_plan_id'
            )
            ->where('orders.course_id', $course->id)
            ->where('orders.status', Order::STATUS_APPROVED)
            ->where('orders.financial_status', Order::FINANCIAL_SETTLED)
            ->whereNull('orders.reversed_at')
            ->whereNotNull('orders.access_plan_id')
            ->selectRaw("{$salesPlanCode} as plan_code, COUNT(*) as sales_count, COALESCE(SUM(orders.total_coins),0) as total_coins, COALESCE(SUM(orders.paid_coins),0) as paid_coins, COALESCE(SUM(orders.reward_coins),0) as reward_coins")
            ->groupByRaw($salesPlanCode)
            ->get()
            ->keyBy('plan_code');
        $costSource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.cost_usage_source'))",
            'pgsql' => "metadata->>'cost_usage_source'",
            'sqlite' => "json_extract(metadata, '$.cost_usage_source')",
            default => "''",
        };
        $usageSource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.usage_source'))",
            'pgsql' => "metadata->>'usage_source'",
            'sqlite' => "json_extract(metadata, '$.usage_source')",
            default => "''",
        };
        $deliverySource = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.entitlement_delivered'))",
            'pgsql' => "metadata->>'entitlement_delivered'",
            'sqlite' => "CAST(json_extract(metadata, '$.entitlement_delivered') AS TEXT)",
            default => "'true'",
        };
        $estimatedCost = "COALESCE({$costSource}, '') NOT IN ('provider', 'cache_zero_cost') AND COALESCE({$usageSource}, '') NOT IN ('cached_answer', 'cache_zero_cost')";
        $usage = DB::table('ai_usage_events')
            ->leftJoin(
                'course_access_plans as usage_plan',
                'usage_plan.id',
                '=',
                'ai_usage_events.access_plan_id'
            )
            ->where('ai_usage_events.course_id', $course->id)
            ->where('ai_usage_events.status', 'completed')
            ->whereNotNull('ai_usage_events.access_plan_id')
            ->selectRaw("usage_plan.code as plan_code, ai_usage_events.feature, SUM(CASE WHEN COALESCE({$deliverySource}, 'true') NOT IN ('false', '0') THEN 1 ELSE 0 END) as ai_requests, SUM(CASE WHEN COALESCE({$deliverySource}, 'true') IN ('false', '0') THEN 1 ELSE 0 END) as unanswered_requests, SUM(CASE WHEN {$estimatedCost} THEN 1 ELSE 0 END) as estimated_requests, COALESCE(SUM(ai_usage_events.total_tokens),0) as total_tokens, COALESCE(SUM(ai_usage_events.cost_usd),0) as cost_usd")
            ->groupBy('usage_plan.code', 'ai_usage_events.feature')
            ->get()
            ->keyBy(fn ($row) => $row->plan_code . ':' . $row->feature);

        return $course->accessPlans->mapWithKeys(fn ($plan) => [
            $plan->code => [
                'sales_count' => (int) ($sales->get($plan->code)?->sales_count ?? 0),
                'total_coins' => (int) ($sales->get($plan->code)?->total_coins ?? 0),
                'paid_coins' => (int) ($sales->get($plan->code)?->paid_coins ?? 0),
                'reward_coins' => (int) ($sales->get($plan->code)?->reward_coins ?? 0),
                'chat_requests' => (int) ($usage->get($plan->code . ':course_chat')?->ai_requests ?? 0),
                'chat_unanswered_requests' => (int) ($usage->get($plan->code . ':course_chat')?->unanswered_requests ?? 0),
                'chat_tokens' => (int) ($usage->get($plan->code . ':course_chat')?->total_tokens ?? 0),
                'chat_cost_usd' => (float) ($usage->get($plan->code . ':course_chat')?->cost_usd ?? 0),
                'project_requests' => (int) ($usage->get($plan->code . ':project_feedback')?->ai_requests ?? 0),
                'project_unanswered_requests' => (int) ($usage->get($plan->code . ':project_feedback')?->unanswered_requests ?? 0),
                'project_tokens' => (int) ($usage->get($plan->code . ':project_feedback')?->total_tokens ?? 0),
                'project_cost_usd' => (float) ($usage->get($plan->code . ':project_feedback')?->cost_usd ?? 0),
                'followup_requests' => (int) ($usage->get($plan->code . ':project_followup')?->ai_requests ?? 0),
                'followup_unanswered_requests' => (int) ($usage->get($plan->code . ':project_followup')?->unanswered_requests ?? 0),
                'followup_tokens' => (int) ($usage->get($plan->code . ':project_followup')?->total_tokens ?? 0),
                'followup_cost_usd' => (float) ($usage->get($plan->code . ':project_followup')?->cost_usd ?? 0),
                'estimated_cost_requests' => (int) collect(['course_chat', 'project_feedback', 'project_followup'])->sum(
                    fn (string $feature): int => (int) ($usage->get($plan->code . ':' . $feature)?->estimated_requests ?? 0)
                ),
                'total_unanswered_requests' => (int) collect(['course_chat', 'project_feedback', 'project_followup'])->sum(
                    fn (string $feature): int => (int) ($usage->get($plan->code . ':' . $feature)?->unanswered_requests ?? 0)
                ),
            ],
        ]);
    }

    /** @param list<int|string> $additionalClassificationIds */
    private function forgetCatalogCache(Course $course, array $additionalClassificationIds = []): void
    {
        try {
            $catalogRevisionKey = 'courses:catalog-revision';
            Cache::add(
                $catalogRevisionKey,
                max(1, (int) floor(microtime(true) * 1000)),
                now()->addYears(10)
            );
            Cache::increment($catalogRevisionKey);
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

    private function canManageCourses(): bool
    {
        return in_array(
            strtolower((string) optional(auth()->user())->role),
            ['admin', 'moderator'],
            true
        );
    }

}
