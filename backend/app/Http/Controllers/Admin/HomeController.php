<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Services\CoursePublishingService;
use App\Services\PaymentChannelReportService;
use App\Support\BusinessClock;


class HomeController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return \App\Models\DesignSetting::getDefaultSettings();
    }


    public function index(
        CoursePublishingService $publishingService,
        PaymentChannelReportService $paymentChannels
    )
    {
        // Content moderators work in course authoring. The executive dashboard
        // and student-submission review contain financial or learner data.
        if (strtolower((string) optional(auth()->user())->role) !== 'admin') {
            $courses = Course::query()
                ->with([
                    'photo',
                    'teachers:id,name,name_ar,name_en,profile_image',
                    'classifications:id,name_ar,name_en',
                ])
                ->withCount(['modules', 'sections'])
                ->whereNull('parent_id')
                ->latest('updated_at')
                ->latest('id')
                ->paginate(12)
                ->withQueryString();
            $publishingAudits = $courses->getCollection()->mapWithKeys(
                fn (Course $course): array => [$course->id => $publishingService->auditCatalogCard($course)]
            );
            $contentSummary = [
                'courses' => Course::query()->whereNull('parent_id')->count(),
                'modules' => CourseModule::query()->whereHas('course', fn ($query) => $query->whereNull('parent_id'))->count(),
                'sections' => CourseSection::query()->whereHas('course', fn ($query) => $query->whereNull('parent_id'))->count(),
                'published' => Course::query()->whereNull('parent_id')->where('is_coming_soon', false)->count(),
            ];

            return view('admin.home.moderator', compact('courses', 'publishingAudits', 'contentSummary'));
        }

        // Cash-channel totals exclude sandbox/test transactions. Wallet totals
        // remain virtual units and are reported independently below.
        $pendingCashOrders = Order::query()
            ->whereIn('payment_method', $paymentChannels->methods())
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_PENDING);

        $paymentChannelReport = $paymentChannels->summary();
        $totalRevenue = (float) $paymentChannelReport['egp']['confirmed_gross_amount'];
        $pendingCash = (float) (clone $pendingCashOrders)->sum('final_amount');

        $businessNow = BusinessClock::now();
        $chartStart = $businessNow->subMonths(5)->startOfMonth()->utc();
        $chartEnd = $businessNow->addMonth()->startOfMonth()->utc();
        $monthlyGross = $paymentChannels->monthlyEgpGross($chartStart, $chartEnd);
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $businessNow->subMonths($i);
            $monthName = $date->locale('ar')->format('M Y');
            $monthCashRevenue = (float) $monthlyGross->get($date->format('Y-m'), 0);

            $monthlyRevenue[] = [
                'month' => $monthName,
                'course_revenue' => $monthCashRevenue,
            ];
        }

        // Revenue Statistics Summary
        $revenueStats = [
            'total_revenue' => $totalRevenue,
            'catalog_estimated_revenue' => (float) $paymentChannelReport['egp']['catalog_estimated_gross_amount'],
            'pending_payments' => $pendingCash,
            'pending_bills_count' => (clone $pendingCashOrders)->count(),
            'confirmed_net_revenue' => $paymentChannelReport['egp']['confirmed_net_amount'],
            'provider_settlement_pending_count' => $paymentChannelReport['egp']['pending_settlement_count'],
        ];

        $currentMonth = $businessNow;
        $previousMonth = $businessNow->subMonth();
        $currentMonthRevenue = (float) $monthlyGross->get($currentMonth->format('Y-m'), 0);
        $previousMonthRevenue = (float) $monthlyGross->get($previousMonth->format('Y-m'), 0);

        $revenueStats['current_month_revenue'] = $currentMonthRevenue;
        $revenueStats['previous_month_revenue'] = $previousMonthRevenue;

        $revenueStats['revenue_growth'] = $previousMonthRevenue > 0
            ? (float) ((($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100)
            : 0;

        $monthStart = $currentMonth->startOfMonth()->utc();
        $nextMonthStart = $currentMonth->addMonth()->startOfMonth()->utc();
        $courseStats = Order::query()
            ->select('course_id')
            ->selectRaw('COUNT(*) as total_buy_count')
            ->selectRaw('SUM(COALESCE(paid_coins, 0)) as paid_coins')
            ->selectRaw('SUM(COALESCE(reward_coins, 0)) as reward_coins')
            ->selectRaw(
                'SUM(CASE WHEN approved_at >= ? AND approved_at < ? THEN 1 ELSE 0 END) as current_month_buy_count',
                [$monthStart, $nextMonthStart]
            )
            ->whereNotNull('course_id')
            ->whereHas('course', fn ($course) => $course->whereNull('parent_id'))
            ->whereIn('payment_method', [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ])
            ->financiallyEffective()
            ->with('course:id,name_ar,name_en')
            ->groupBy('course_id')
            ->get()
            ->map(fn (Order $order): array => [
                'name' => (string) ($order->course?->name_ar ?: $order->course?->name_en),
                'total_buy_count' => (int) $order->total_buy_count,
                'paid_coins' => (int) $order->paid_coins,
                'reward_coins' => (int) $order->reward_coins,
                'current_month_buy_count' => (int) $order->current_month_buy_count,
            ])
            ->sortByDesc('paid_coins')
            ->values();

        $designSettings = $this->getDesignSettings();
        $platformStats = [
            'courses' => Course::query()->whereNull('parent_id')->count(),
            'lessons' => \App\Models\Lesson::query()->count(),
            'students' => User::query()->students()->count(),
        ];

        return view('admin.home.index', compact(
            'designSettings',
            'revenueStats',
            'monthlyRevenue',
            'paymentChannelReport',
            'courseStats',
            'platformStats'
        ));
    }
}
