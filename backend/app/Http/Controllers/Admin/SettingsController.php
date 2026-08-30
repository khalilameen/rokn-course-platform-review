<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\CourseSection;
use App\Models\DesignSetting;
use App\Models\Lesson;
use App\Models\Setting;
use App\Services\BunnyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const VERIFIED_CLEANUP_REASONS = [
        'publish_race_or_failure',
        'superseded_video',
        'unpublished_upload',
        'section_create_rollback',
        'section_update_rollback',
        'section_type_changed',
        'section_deleted',
    ];

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Keep page reads idempotent. Database defaults are persisted only by
        // the explicit POST below, not by opening the settings screen.
        $settings = Setting::query()->first() ?? new Setting();
        $bunnyCleanupCandidates = collect();
        $bunnyCleanupStats = ['pending_review' => 0, 'approved' => 0, 'deleted' => 0];
        if (Schema::hasTable('bunny_video_cleanup_candidates')) {
            $base = BunnyVideoCleanupCandidate::query();
            $bunnyCleanupStats = [
                'pending_review' => (clone $base)->whereNull('remote_deleted_at')->whereNull('reviewed_at')->count(),
                'approved' => (clone $base)->whereNull('remote_deleted_at')->whereNotNull('reviewed_at')->count(),
                'deleted' => (clone $base)->whereNotNull('remote_deleted_at')->count(),
            ];
            $cleanupFilter = (string) $request->query('cleanup_filter', 'verified');
            $candidateQuery = BunnyVideoCleanupCandidate::query();
            if ($cleanupFilter === 'verified') {
                $candidateQuery->whereIn('reason', self::VERIFIED_CLEANUP_REASONS);
            } elseif ($cleanupFilter === 'failed') {
                $candidateQuery->whereNotNull('last_error');
            }
            $bunnyCleanupCandidates = $candidateQuery->latest('updated_at')
                ->limit(20)
                ->get();
        } else {
            $cleanupFilter = 'verified';
        }

        return view('admin.settings.index', compact(
            'settings',
            'bunnyCleanupCandidates',
            'bunnyCleanupStats',
            'cleanupFilter'
        ));
    }

    public function approveBunnyCleanup(
        Request $request,
        BunnyVideoCleanupCandidate $candidate
    ) {
        abort_if($candidate->remote_deleted_at, 409, 'تم حذف هذا الفيديو بالفعل');

        $activeReference = CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $candidate->video_guid)
            ->exists();
        if ($activeReference) {
            return redirect()->route('admin.settings')
                ->with('error', 'لا يمكن اعتماد الحذف لأن الفيديو ما زال مستخدمًا في قسم منشور');
        }

        $candidate->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'requires_review' => false,
            'eligible_after' => $candidate->eligible_after->isFuture()
                ? $candidate->eligible_after
                : now(),
            'last_error' => null,
        ])->save();

        return redirect()->route('admin.settings')
            ->with('success', 'تم اعتماد الفيديو للتنظيف بعد انتهاء فترة الاحتفاظ');
    }

    public function approveBunnyCleanupBatch(Request $request)
    {
        $validated = $request->validate([
            'cleanup_ids' => 'required|array|min:1|max:100',
            'cleanup_ids.*' => 'required|integer|distinct|exists:bunny_video_cleanup_candidates,id',
        ]);

        $approved = 0;
        $skippedActive = 0;
        DB::transaction(function () use ($validated, $request, &$approved, &$skippedActive): void {
            $candidates = BunnyVideoCleanupCandidate::query()
                ->whereIn('id', $validated['cleanup_ids'])
                ->whereNull('remote_deleted_at')
                ->whereNull('reviewed_at')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                if ($this->bunnyVideoHasActiveReference($candidate->video_guid)) {
                    $skippedActive++;
                    continue;
                }

                $candidate->forceFill([
                    'reviewed_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'requires_review' => false,
                    'eligible_after' => $candidate->eligible_after->isFuture()
                        ? $candidate->eligible_after
                        : now(),
                    'last_error' => null,
                ])->save();
                $approved++;
            }
        });

        $message = "تم اعتماد {$approved} فيديو للتنظيف بعد فترة الاحتفاظ";
        if ($skippedActive > 0) {
            $message .= " وتجاوز {$skippedActive} فيديو ما زال مستخدمًا";
        }

        return redirect()->route('admin.settings', ['cleanup_filter' => 'verified'])
            ->with($skippedActive > 0 ? 'warning' : 'success', $message);
    }

    private function bunnyVideoHasActiveReference(string $videoGuid): bool
    {
        return CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $videoGuid)
            ->exists();
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
            'site_name_ar' => 'nullable|string|max:255',
            'site_name_en' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'currency_code' => 'nullable|string|max:10',
            'direct_checkout_discount_percent' => 'required|numeric|min:0|max:50',
            'google_maps_key' => 'nullable|string',
            'contact' => 'nullable|string',
            'seo_meta_title_ar' => 'nullable|string|max:255',
            'seo_meta_description_ar' => 'nullable|string|max:500',
            'seo_meta_title_en' => 'nullable|string|max:255',
            'seo_meta_description_en' => 'nullable|string|max:500',
            'english_translation' => 'nullable|boolean',
            'device_login_policy' => 'nullable|string',
            'enforce_course_section_order' => 'nullable|boolean',
            'bunny_enabled' => 'nullable|boolean',
            'bunny_api_key' => 'nullable|string|max:4096',
            'bunny_library_id' => 'nullable|string',
            'bunny_cdn_hostname' => 'nullable|string',
            'bunny_storage_zone_name' => 'nullable|string',
            'bunny_storage_password' => 'nullable|string|max:4096',
            'bunny_security_key' => 'nullable|string|max:4096',
            'android_app_url' => 'nullable|url|max:500',
            'ios_app_url' => 'nullable|url|max:500',
            'about_us_url' => 'nullable|string',
            'privacy_policy_url' => 'nullable|string',
            'support_whatsapp_url' => 'nullable|string|max:255',
            'ai_daily_user_limit' => 'sometimes|required|integer|min:1|max:1000',
            'ai_global_daily_request_limit' => 'sometimes|required|integer|min:1|max:10000000',
            'ai_global_daily_token_budget' => 'sometimes|required|integer|min:1000|max:1000000000',
            'ai_global_monthly_token_budget' => 'sometimes|required|integer|min:1000|max:10000000000',
            'ai_answer_cache_minutes' => 'sometimes|required|integer|min:5|max:10080',
            ]);
        } catch (ValidationException $exception) {
            $this->forgetBunnySecretInputs($request);
            throw $exception;
        }

        $secretUpdates = [];
        if (!empty($validated['bunny_api_key'])) {
            $secretUpdates['bunny_api_key_secret'] = $validated['bunny_api_key'];
        }
        if (!empty($validated['bunny_storage_password'])) {
            $secretUpdates['bunny_storage_password_secret'] = $validated['bunny_storage_password'];
        }
        if (!empty($validated['bunny_security_key'])) {
            $secretUpdates['bunny_security_key_secret'] = $validated['bunny_security_key'];
        }
        unset($validated['bunny_api_key'], $validated['bunny_storage_password'], $validated['bunny_security_key']);
        $this->forgetBunnySecretInputs($request);

        if (!empty($validated['support_whatsapp_url'])) {
            $normalizedWhatsAppUrl = $this->normalizeWhatsAppUrl($validated['support_whatsapp_url']);
            if ($normalizedWhatsAppUrl === null) {
                throw ValidationException::withMessages([
                    'support_whatsapp_url' => ['أدخل رقمًا دوليًا مثل +201001234567 أو رابطًا يبدأ بـ https://wa.me/.'],
                ]);
            }
            $validated['support_whatsapp_url'] = $normalizedWhatsAppUrl;
        }

        $settings = Setting::firstOrCreate([]);

        $settings->update($validated + $secretUpdates);
        Cache::forget('home:general-settings:v1');

        return redirect()->route('admin.settings')->with('success', 'تم التحديث بنجاح');
    }

    private function normalizeWhatsAppUrl(string $value): ?string
    {
        $value = trim($value);
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

        if (preg_match('/^[1-9][0-9]{7,14}$/', $digits) !== 1) {
            return null;
        }

        return 'https://wa.me/' . $digits;
    }

    private function forgetBunnySecretInputs(Request $request): void
    {
        foreach (['bunny_api_key', 'bunny_storage_password', 'bunny_security_key', 'api_key'] as $field) {
            $request->request->remove($field);
        }
    }

    public function adminData()
    {
        return view('admin.settings.admin_data');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateAdminData(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:10', 'max:72'],
        ]);

        // This form only updates the administrator's login credentials.
        $user->email = strtolower(trim($validated['email']));
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();

        return redirect()->route('admin.admin_data')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Display the student platform URL page
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function studentPlatform()
    {
        $platformUrl = config('app.url', url('/'));

        // Get design settings for platform name
        $designSettings = DesignSetting::getDefaultSettings();

        return view('admin.settings.student-platform', compact('platformUrl', 'designSettings'));
    }

    /**
     * Test Bunny.net connection
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testBunnyConnection(Request $request)
    {
        try {
            $request->validate([
                'api_key' => 'nullable|string|max:4096',
                'library_id' => 'nullable|string',
            ]);
        } catch (ValidationException $exception) {
            $this->forgetBunnySecretInputs($request);
            throw $exception;
        }

        $submittedApiKey = $request->input('api_key');
        $this->forgetBunnySecretInputs($request);
        $settings = Setting::first();
        $apiKey = $submittedApiKey
            ?: config('bunny.stream_api_key')
            ?: $settings?->bunny_api_key_secret;
        $libraryId = $request->input('library_id')
            ?: config('bunny.library_id')
            ?: $settings?->bunny_library_id;
        if (!$apiKey || !$libraryId) {
            return response()->json(['success' => false, 'message' => 'بيانات Bunny غير مكتملة.'], 422);
        }

        $result = BunnyService::testConnection(
            $apiKey,
            $libraryId
        );

        return response()->json($result);
    }
}
