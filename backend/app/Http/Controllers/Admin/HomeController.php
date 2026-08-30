<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Services\CoursePublishingService;
use App\Services\PaymentChannelReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;


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

        $provider_requests = User::where('provider_request', true)->get();

        // Get visitor statistics for the last month
        $lastMonth = now()->subMonth();
        $visitorStats = \App\Models\Visitor::where('visited_at', '>=', $lastMonth)
            ->selectRaw('DATE(visited_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count
                ];
            });

        // Get daily visitor counts for the last 30 days
        $dailyVisitors = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $count = $visitorStats->where('date', $date)->first()['count'] ?? 0;
            $dailyVisitors[] = [
                'date' => now()->subDays($i)->format('M d'),
                'count' => $count
            ];
        }

        // Get browser statistics
        $browserStats = \App\Models\Visitor::where('visited_at', '>=', $lastMonth)
            ->selectRaw('browser, COUNT(*) as count')
            ->groupBy('browser')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get();

        // Get device type statistics
        $deviceStats = \App\Models\Visitor::where('visited_at', '>=', $lastMonth)
            ->selectRaw('device_type, COUNT(*) as count')
            ->groupBy('device_type')
            ->orderBy('count', 'desc')
            ->get();

        // Cash-channel totals exclude sandbox/test transactions. Wallet totals
        // remain virtual units and are reported independently below.
        $approvedCashOrders = Order::query()
            ->whereIn('payment_method', $paymentChannels->methods())
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_APPROVED)
            ->where(function ($query): void {
                $query->whereNull('gateway_settlement_status')
                    ->orWhere('gateway_settlement_status', '<>', 'test_purchase');
            });
        $pendingCashOrders = Order::query()
            ->whereIn('payment_method', $paymentChannels->methods())
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_PENDING);

        $paymentChannelReport = $paymentChannels->summary();
        $totalRevenue = (float) $paymentChannelReport['egp']['gross_amount'];
        $pendingCash = (float) (clone $pendingCashOrders)->sum('final_amount');

        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthName = $date->locale('ar')->format('M Y');
            $monthReport = $paymentChannels->summary(
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth()
            );
            $monthCashRevenue = (float) $monthReport['egp']['gross_amount'];

            $monthlyRevenue[] = [
                'month' => $monthName,
                'course_revenue' => $monthCashRevenue,
                'subscription_revenue' => 0,
                'total' => $monthCashRevenue,
            ];
        }

        $paymentMethods = $paymentChannelReport['rows']
            ->where('currency', 'EGP')
            ->map(function(array $item) {
                return [
                    'method' => $item['label'],
                    'total' => (float) $item['gross_amount'],
                ];
            });

        // Revenue Statistics Summary
        $revenueStats = [
            'total_revenue' => $totalRevenue,
            'course_revenue' => $totalRevenue,
            'subscription_revenue' => 0,
            'pending_payments' => $pendingCash,
            'paid_bills_count' => (clone $approvedCashOrders)->count(),
            'pending_bills_count' => (clone $pendingCashOrders)->count(),
            'active_subscriptions_count' => 0,
            'confirmed_net_revenue' => $paymentChannelReport['egp']['confirmed_net_amount'],
            'estimated_net_revenue' => $paymentChannelReport['egp']['estimated_net_amount'],
            'pending_settlements_count' => $paymentChannelReport['egp']['pending_settlement_count'],
        ];

        $currentMonth = now();
        $previousMonth = now()->subMonth();
        $currentMonthRevenue = (float) $paymentChannels->summary(
            $currentMonth->copy()->startOfMonth(),
            $currentMonth->copy()->endOfMonth()
        )['egp']['gross_amount'];
        $previousMonthRevenue = (float) $paymentChannels->summary(
            $previousMonth->copy()->startOfMonth(),
            $previousMonth->copy()->endOfMonth()
        )['egp']['gross_amount'];

        $revenueStats['current_month_revenue'] = $currentMonthRevenue;
        $revenueStats['current_month_course_revenue'] = $currentMonthRevenue;
        $revenueStats['current_month_subscription_revenue'] = 0;
        $revenueStats['current_month_target_subscription'] = 0;
        $revenueStats['previous_month_revenue'] = $previousMonthRevenue;
        $revenueStats['previous_month_course_revenue'] = $previousMonthRevenue;
        $revenueStats['previous_month_subscription_revenue'] = 0;

        $revenueStats['revenue_growth'] = $previousMonthRevenue > 0
            ? (float) ((($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100)
            : 0;

        $courseStats = \App\Models\Course::whereNull('parent_id')
            ->get()
            ->map(function($course) use ($currentMonth) {
                $orders = Order::query()
                    ->where('course_id', $course->id)
                    ->whereIn('payment_method', [
                        Order::PAYMENT_METHOD_WALLET,
                        Order::PAYMENT_METHOD_WALLET_COINS,
                    ])
                    ->where('status', Order::STATUS_APPROVED);
                $currentOrders = (clone $orders)
                    ->whereYear('approved_at', $currentMonth->year)
                    ->whereMonth('approved_at', $currentMonth->month);

                return [
                    'id' => $course->id,
                    'name' => $course->name_ar ?: $course->name_en,
                    'total_buy_count' => (clone $orders)->count(),
                    'total_revenue' => (int) (clone $orders)->sum('total_coins'),
                    'paid_coins' => (int) (clone $orders)->sum('paid_coins'),
                    'reward_coins' => (int) (clone $orders)->sum('reward_coins'),
                    'current_month_buy_count' => (clone $currentOrders)->count(),
                    'current_month_revenue' => (int) (clone $currentOrders)->sum('total_coins'),
                    'current_month_paid_coins' => (int) (clone $currentOrders)->sum('paid_coins'),
                    'current_month_reward_coins' => (int) (clone $currentOrders)->sum('reward_coins'),
                ];
            })
            ->filter(function($course) {
                return $course['total_buy_count'] > 0;
            })
            ->sortByDesc('paid_coins')
            ->values();

        $designSettings = $this->getDesignSettings();

        return view('admin.home.index', compact(
            'provider_requests',
            'dailyVisitors',
            'browserStats',
            'deviceStats',
            'designSettings',
            'revenueStats',
            'monthlyRevenue',
            'paymentMethods',
            'paymentChannelReport',
            'courseStats'
        ));
    }
}
