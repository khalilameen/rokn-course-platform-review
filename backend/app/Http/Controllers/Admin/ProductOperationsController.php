<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Attachment;
use App\Models\CoinEarningMethod;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseGrantClaim;
use App\Models\FinancialAnomaly;
use App\Models\CourseModule;
use App\Models\Order;
use App\Models\Package;
use App\Models\PortfolioItem;
use App\Models\ProjectSubmission;
use App\Models\ProductFeatureFlag;
use App\Models\Setting;
use App\Models\StudentNotification;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\PlaybackSession;
use App\Services\ProductionCapabilityService;
use App\Services\OperationsReadinessService;
use App\Services\PlaybackOperationsService;
use App\Services\ProductFeatureFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ProductOperationsController extends Controller
{
    public function index(
        ProductionCapabilityService $capabilities,
        PlaybackOperationsService $playbackOperationsService,
        OperationsReadinessService $operationsReadiness,
        ProductFeatureFlagService $productFeatureFlags
    ): View
    {
        $courseAllocation = static function ($query): void {
            $query->where('status', Order::STATUS_APPROVED)
                ->whereIn('payment_method', [
                    Order::PAYMENT_METHOD_WALLET,
                    Order::PAYMENT_METHOD_WALLET_COINS,
                ]);
        };

        $courses = Course::query()
            ->whereNull('parent_id')
            ->withCount(['sections', 'modules', 'activeEnrollments', 'ratings'])
            ->withAvg('ratings', 'rating')
            ->withSum(['orders as total_coins_spent' => $courseAllocation], 'total_coins')
            ->withSum(['orders as paid_coins_spent' => $courseAllocation], 'paid_coins')
            ->withSum(['orders as reward_coins_spent' => $courseAllocation], 'reward_coins')
            ->orderByDesc('is_main_course')
            ->orderByDesc('id')
            ->get();

        $settings = Setting::query()->first() ?? new Setting();
        $capabilityReport = $capabilities->report();
        $legacyPublicAttachments = Attachment::query()
            ->where('attachable_type', CourseModule::class)
            ->where(function ($query): void {
                $query->whereNull('storage_disk')->orWhere('storage_disk', 'public');
            })
            ->count();

        $readiness = [
            'hero' => Course::query()->whereNull('parent_id')->where('is_main_course', true)->count() === 1,
            'published_course' => Course::query()->whereNull('parent_id')->where('is_coming_soon', false)->exists(),
            'packages' => Package::query()->where('price', '>', 0)->where('coins', '>', 0)->exists(),
            'reward_tasks' => CoinEarningMethod::query()->active()->exists(),
            'support' => filled($settings->support_whatsapp_url),
            'private_attachments' => $legacyPublicAttachments === 0,
        ];

        $grantUpgradeOrders = Order::query()
            ->where('status', Order::STATUS_APPROVED)
            ->where(function ($upgrades): void {
                $upgrades->where('notes', 'like', 'Full-track upgrade from grant order #%')
                    ->orWhereHas('parentOrder.courseCode', function ($codes): void {
                        $codes->where('is_grant', true)
                            ->orWhereNotNull('allowed_email_domains');
                    });
            });

        $hasIntegrityState = Schema::hasTable('lesson_media_states')
            && Schema::hasColumn('lesson_media_states', 'integrity_status');
        $mediaAttentionCount = LessonMediaState::query()
            ->where(function ($query) use ($hasIntegrityState): void {
                $query->whereIn('status', ['unknown', 'processing', 'failed']);
                if ($hasIntegrityState) {
                    $query->orWhereIn('integrity_status', ['attention', 'quarantined']);
                }
            })
            ->count();

        $counts = [
            'courses' => $courses->count(),
            'published' => $courses->where('is_coming_soon', false)->count(),
            'coming_soon' => $courses->where('is_coming_soon', true)->where('is_catalog_visible', true)->count(),
            'packages' => Package::query()->count(),
            'reward_tasks' => CoinEarningMethod::query()->active()->count(),
            'grants' => CourseCode::query()
                ->where(function ($query): void {
                    $query->where('is_grant', true)
                        ->orWhereNotNull('allowed_email_domains');
                })
                ->count(),
            'grant_claims' => CourseGrantClaim::query()->count(),
            'grant_upgrades' => (clone $grantUpgradeOrders)->count(),
            'pending_projects' => ProjectSubmission::query()->where('review_status', ProjectSubmission::STATUS_PENDING)->count(),
            'certificates' => Certificate::query()->count(),
            'portfolio_items' => PortfolioItem::query()->count(),
            'notifications' => StudentNotification::query()->count(),
            'legacy_public_attachments' => $legacyPublicAttachments,
            'external_attachment_links' => CourseModule::query()
                ->whereNotNull('attachments_link')
                ->where('attachments_link', '<>', '')
                ->count(),
            'media_ready' => LessonMediaState::query()->where('status', 'ready')->count(),
            'media_attention' => $mediaAttentionCount
                + Lesson::query()->where(function ($query): void {
                    $query->where('video_source_type', '<>', 'bunny')->orWhereNull('bunny_video_id')->orWhere('bunny_video_id', '');
                })->count(),
            'playback_sessions_today' => PlaybackSession::query()->where('started_at', '>=', now()->startOfDay())->count(),
            'financial_anomalies' => Schema::hasTable('financial_anomalies')
                ? FinancialAnomaly::query()->where('status', FinancialAnomaly::STATUS_OPEN)->count()
                : 0,
        ];

        $financialAnomalies = Schema::hasTable('financial_anomalies')
            ? FinancialAnomaly::query()
                ->with([
                    'user:id,name,email',
                    'course:id,name_ar,name_en',
                    'order:id,order_ref',
                ])
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->latest('detected_at')
                ->limit(20)
                ->get()
            : collect();

        $mediaAttention = Lesson::query()
            ->with(['course:id,name_ar,name_en', 'mediaState'])
            ->where(function ($query) use ($hasIntegrityState): void {
                $query->whereDoesntHave('mediaState')
                    ->orWhereHas('mediaState', function ($state) use ($hasIntegrityState): void {
                        $state->where('status', '<>', 'ready');
                        if ($hasIntegrityState) {
                            $state->orWhereIn('integrity_status', ['attention', 'quarantined']);
                        }
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        $finance = [
            'cash_revenue' => (float) Order::query()
                ->where('status', Order::STATUS_APPROVED)
                ->where('financial_status', Order::FINANCIAL_SETTLED)
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->sum('final_amount'),
            'course_coins' => (int) $courses->sum('total_coins_spent'),
            'course_paid_coins' => (int) $courses->sum('paid_coins_spent'),
            'course_reward_coins' => (int) $courses->sum('reward_coins_spent'),
            'refunds' => Order::query()->whereIn('financial_status', [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ])->count(),
            'grant_upgrade_paid_coins' => (int) (clone $grantUpgradeOrders)->sum('paid_coins'),
            'grant_upgrade_reward_coins' => (int) (clone $grantUpgradeOrders)->sum('reward_coins'),
        ];

        $playbackOperations = $playbackOperationsService->snapshot(12);
        $mediaReconcileStatus = $operationsReadiness->mediaReconcileStatus();
        $backupReadiness = $operationsReadiness->backupReadiness();
        $featureFlags = $productFeatureFlags->operationalSnapshot();

        return view('admin.product_operations', compact(
            'courses', 'settings', 'readiness', 'counts', 'finance', 'capabilityReport', 'mediaAttention',
            'playbackOperations', 'mediaReconcileStatus', 'backupReadiness', 'featureFlags',
            'financialAnomalies'
        ));
    }

    public function updateFeature(Request $request, string $feature): RedirectResponse
    {
        $definitions = config('product_features.definitions', []);
        abort_unless(array_key_exists($feature, $definitions), 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rollout_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $administrator = $request->user();
        $owner = $administrator?->email
            ?: 'admin:'.(string) ($administrator?->getAuthIdentifier() ?? 'unknown');

        ProductFeatureFlag::query()->updateOrCreate(
            ['key' => $feature],
            [
                'enabled' => (bool) $validated['enabled'],
                'rollout_percentage' => (int) $validated['rollout_percentage'],
                'owner' => $owner,
                'reason' => trim((string) $validated['reason']),
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        return back()->with('success', 'تم تحديث بوابة الميزة مع حفظ المسؤول والسبب.');
    }
}
