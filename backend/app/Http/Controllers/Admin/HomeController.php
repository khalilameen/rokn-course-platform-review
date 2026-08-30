<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Bill;
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


    public function index()
    {
        // Content moderators work in course authoring. The executive dashboard
        // and student-submission review contain financial or learner data.
        if (strtolower((string) optional(auth()->user())->role) !== 'admin') {
            return redirect()->route('admin.courses.index');
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

        // Cash totals use approved Kashier orders; wallet totals remain virtual units.
        $approvedCashOrders = Order::query()
            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_APPROVED);
        $pendingCashOrders = Order::query()
            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_PENDING);

        $totalRevenue = (float) (clone $approvedCashOrders)->sum('final_amount');
        $pendingCash = (float) (clone $pendingCashOrders)->sum('final_amount');

        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthName = $date->locale('ar')->format('M Y');
            $monthCashRevenue = (float) (clone $approvedCashOrders)
                ->whereYear('approved_at', $date->year)
                ->whereMonth('approved_at', $date->month)
                ->sum('final_amount');

            $monthlyRevenue[] = [
                'month' => $monthName,
                'course_revenue' => $monthCashRevenue,
                'subscription_revenue' => 0,
                'total' => $monthCashRevenue,
            ];
        }

        $paymentMethods = (clone $approvedCashOrders)
            ->selectRaw('payment_method, SUM(final_amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(function($item) {
                return [
                    'method' => $item->payment_method,
                    'total' => (float) $item->total
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
        ];

        $currentMonth = now();
        $previousMonth = now()->subMonth();
        $currentMonthRevenue = (float) (clone $approvedCashOrders)
            ->whereYear('approved_at', $currentMonth->year)
            ->whereMonth('approved_at', $currentMonth->month)
            ->sum('final_amount');
        $previousMonthRevenue = (float) (clone $approvedCashOrders)
            ->whereYear('approved_at', $previousMonth->year)
            ->whereMonth('approved_at', $previousMonth->month)
            ->sum('final_amount');

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
            'courseStats'
        ));
    }
}
