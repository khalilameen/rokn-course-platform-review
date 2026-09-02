<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\WalletDebitAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Builds the administrator's auditable learner and cash-attribution report. */
final class CourseCommercialReportService
{
    public function __construct(private readonly CourseCostReportService $costs)
    {
    }

    /** @return array<string, mixed> */
    public function forCourse(Course $course): array
    {
        $enrollments = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->with(['user', 'order.courseCode', 'accessPlanOrder.courseCode', 'accessPlan'])
            ->orderByDesc('is_active')
            ->orderByDesc('access_granted_at')
            ->get();

        $orders = Order::query()
            ->where('course_id', $course->id)
            ->financiallyEffective()
            ->with(['user', 'courseCode', 'accessPlan'])
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $allocationsByOrder = $this->allocationsFor($orders);
        $ordersByUser = $orders->groupBy(fn (Order $order): int => (int) $order->user_id);
        $costReport = $this->costs->forCourse(
            $course,
            $enrollments->pluck('user_id')->map(fn ($id): int => (int) $id)
        );
        $rows = $enrollments->map(function (CourseEnrollment $enrollment) use (
            $ordersByUser,
            $allocationsByOrder,
            $costReport
        ): array {
            /** @var Collection<int, Order> $learnerOrders */
            $learnerOrders = $ordersByUser->get((int) $enrollment->user_id, collect());
            $cash = $this->cashForOrders($learnerOrders, $allocationsByOrder);
            $currentOrder = $enrollment->accessPlanOrder ?: $enrollment->order;
            $snapshot = is_array($enrollment->access_plan_snapshot)
                ? $enrollment->access_plan_snapshot
                : (is_array($currentOrder?->access_plan_snapshot)
                    ? $currentOrder->access_plan_snapshot
                    : []);
            $grantOrder = $learnerOrders->first(
                fn (Order $order): bool => $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
                    && (bool) $order->courseCode?->isInstitutionalGrant()
            );
            $codeOrder = $learnerOrders->first(
                fn (Order $order): bool => $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
            );
            $hasPaidOrder = $learnerOrders->contains(
                fn (Order $order): bool => (int) $order->paid_coins > 0
            );
            $source = $grantOrder && $hasPaidOrder
                ? 'grant_plus_purchase'
                : ($grantOrder ? 'grant' : ($codeOrder && $hasPaidOrder
                    ? 'code_plus_purchase'
                    : ($codeOrder ? 'course_code' : 'purchase')));
            $sourceLabel = match ($source) {
                'grant_plus_purchase' => 'منحة + شراء/ترقية',
                'code_plus_purchase' => 'كود إتاحة + شراء/ترقية',
                'grant' => 'منحة',
                'course_code' => 'كود إتاحة',
                default => 'شراء',
            };

            $cost = $costReport['users']->get(
                (int) $enrollment->user_id,
                [
                    'ai_requests' => 0, 'ai_failed_requests' => 0,
                    'ai_unanswered_requests' => 0, 'ai_tokens' => 0,
                    'ai_estimated_requests' => 0, 'ai_cost_complete' => true,
                    'ai_measurement_available' => true,
                    'ai_cost_usd' => 0.0, 'ai_cost_egp' => 0.0,
                    'playback_minutes' => 0.0, 'playback_gb_estimated' => 0.0,
                    'allocated_operating_cost_egp' => 0.0,
                    'estimated_operating_cost_egp' => 0.0,
                    'service_cost_actual_egp' => 0.0,
                    'service_cost_with_estimates_egp' => 0.0,
                    'service_cost_complete' => true,
                    'service_cost_estimate_complete' => true,
                ]
            );

            $row = [
                'enrollment' => $enrollment,
                'user' => $enrollment->user,
                'is_active' => $enrollment->isActive(),
                'source' => $source,
                'source_label' => $sourceLabel,
                'plan_code' => (string) ($snapshot['code'] ?? $enrollment->accessPlan?->code ?? ''),
                'plan_name' => (string) ($snapshot['name_ar'] ?? $enrollment->accessPlan?->name_ar ?? 'إتاحة قديمة'),
                'contract_price_coins' => isset($snapshot['price_coins'])
                    ? (int) $snapshot['price_coins']
                    : null,
                'discount_coins' => (int) $learnerOrders->sum('discount_amount'),
                'coupon_codes' => $learnerOrders->pluck('coupon_code')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'total_coins' => (int) $learnerOrders->sum('total_coins'),
                'paid_coins' => (int) $learnerOrders->sum('paid_coins'),
                'reward_coins' => (int) $learnerOrders->sum('reward_coins'),
                'orders_count' => $learnerOrders->count(),
                'purchased_at' => $currentOrder?->approved_at ?: $enrollment->access_granted_at,
            ] + $cash + $cost;
            $row['contribution_margin_egp'] = $row['cash_net_complete']
                && $row['service_cost_complete']
                && $row['service_cost_actual_egp'] !== null
                    ? round(
                        (float) $row['cash_net_known_egp']
                        - (float) $row['service_cost_actual_egp'],
                        2
                    )
                    : null;
            $row['estimated_contribution_margin_egp'] = $row['cash_net_complete']
                && $row['service_cost_with_estimates_egp'] !== null
                    ? round(
                        (float) $row['cash_net_known_egp']
                        - (float) $row['service_cost_with_estimates_egp'],
                        2
                    )
                    : null;
            $row += $this->unitEconomics(
                $row['cash_net_complete'] ? (float) $row['cash_net_known_egp'] : null,
                $row['service_cost_actual_egp'],
                $row['contribution_margin_egp']
            );

            return $row;
        })->values();

        $gross = round((float) $rows->sum('cash_gross_egp'), 2);
        $estimatedGross = round((float) $rows->sum('cash_estimated_gross_egp'), 2);
        $knownNet = round((float) $rows->sum('cash_net_known_egp'), 2);
        $pendingGross = round((float) $rows->sum('cash_pending_settlement_egp'), 2);
        $cashNetComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $cashGrossComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_gross_complete']);
        $foreignCurrencyExposure = $rows
            ->flatMap(fn (array $row): array => collect($row['cash_foreign_currency_amounts'])
                ->map(fn (float $amount, string $currency): array => [
                    'currency' => $currency,
                    'amount' => $amount,
                ])->values()->all())
            ->groupBy('currency')
            ->map(fn (Collection $items): float => round((float) $items->sum('amount'), 2))
            ->all();
        $serviceCostComplete = $rows->every(
            fn (array $row): bool => (bool) $row['service_cost_complete']
        );
        $serviceCost = $serviceCostComplete
            ? round((float) $rows->sum('service_cost_actual_egp'), 2)
            : null;
        $unitEconomics = $this->unitEconomics(
            $cashNetComplete ? $knownNet : null,
            $serviceCost,
            $cashNetComplete && $serviceCostComplete
                ? round($knownNet - (float) $serviceCost, 2)
                : null
        );
        $cashChannels = $rows
            ->flatMap(fn (array $row): array => array_values($row['cash_channels'] ?? []))
            ->groupBy('method')
            ->map(function (Collection $items): array {
                $foreign = $items->flatMap(function (array $item): array {
                    return collect($item['foreign_currency_amounts'] ?? [])
                        ->map(fn (float $amount, string $currency): array => [
                            'currency' => $currency,
                            'amount' => $amount,
                        ])->values()->all();
                })->groupBy('currency')->map(
                    fn (Collection $amounts): float => round((float) $amounts->sum('amount'), 2)
                )->all();

                return [
                    'method' => (string) $items->first()['method'],
                    'label' => (string) $items->first()['label'],
                    'paid_coins' => (int) $items->sum('paid_coins'),
                    'gross_egp' => round((float) $items->sum('gross_egp'), 2),
                    'estimated_gross_egp' => round((float) $items->sum('estimated_gross_egp'), 2),
                    'gross_complete' => $items->every(fn (array $item): bool => (bool) $item['gross_complete']),
                    'net_known_egp' => round((float) $items->sum('net_known_egp'), 2),
                    'pending_settlement_egp' => round((float) $items->sum('pending_settlement_egp'), 2),
                    'net_complete' => $items->every(fn (array $item): bool => (bool) $item['net_complete']),
                    'foreign_currency_amounts' => $foreign,
                ];
            })->values();

        return [
            'rows' => $rows,
            'active_students' => $rows->where('is_active', true)->count(),
            'historical_students' => $rows->count(),
            'grant_students' => $rows->filter(fn (array $row): bool => str_starts_with($row['source'], 'grant'))->count(),
            'code_students' => $rows->filter(fn (array $row): bool => str_contains($row['source'], 'code'))->count(),
            'paid_students' => $rows->where('paid_coins', '>', 0)->count(),
            'total_coins' => (int) $orders->sum('total_coins'),
            'discount_coins' => (int) $orders->sum('discount_amount'),
            'paid_coins' => (int) $orders->sum('paid_coins'),
            'reward_coins' => (int) $orders->sum('reward_coins'),
            'cash_gross_egp' => $gross,
            'cash_estimated_gross_egp' => $estimatedGross,
            'cash_gross_complete' => $cashGrossComplete,
            'cash_net_known_egp' => $knownNet,
            'cash_pending_settlement_egp' => $pendingGross,
            'cash_net_complete' => $cashNetComplete,
            'cash_foreign_currency_exposure' => $foreignCurrencyExposure,
            'cash_channel_breakdown' => $cashChannels,
            'cash_net_egp' => $cashNetComplete ? $knownNet : null,
            'ai_cost_usd' => $costReport['ai_cost_usd'],
            'ai_estimated_requests' => (int) $rows->sum('ai_estimated_requests'),
            'ai_cost_complete' => $rows->every(fn (array $row): bool => (bool) $row['ai_cost_complete']),
            'playback_minutes' => $costReport['playback_minutes'],
            'playback_gb_estimated' => $costReport['playback_gb_estimated'],
            'service_cost_complete' => $serviceCostComplete,
            'service_cost_actual_egp' => $serviceCost,
            'service_cost_with_estimates_egp' => $costReport['service_cost_with_estimates_egp'],
            'estimated_contribution_margin_egp' => $cashNetComplete
                && $costReport['service_cost_with_estimates_egp'] !== null
                    ? round($knownNet - (float) $costReport['service_cost_with_estimates_egp'], 2)
                    : null,
            'contribution_margin_egp' => $cashNetComplete && $serviceCostComplete
                ? round($knownNet - (float) $serviceCost, 2)
                : null,
            'cost_warnings' => $costReport['unallocated_pools'],
            'service_breakdown' => $costReport['service_breakdown'],
            'plan_breakdown' => $rows->groupBy('plan_name')->map(
                fn (Collection $planRows): array => $this->groupSummary($planRows)
            ),
        ] + $unitEconomics;
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    public function groupSummary(Collection $rows): array
    {
        $enrollments = $rows->count();
        $students = $rows->map(function (array $row): int {
            return (int) ($row['enrollment']?->user_id ?? $row['user']?->id ?? 0);
        })->filter()->unique()->count();
        $netComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $costComplete = $rows->every(fn (array $row): bool => (bool) $row['service_cost_complete']);
        $estimatedComplete = $rows->every(
            fn (array $row): bool => $row['service_cost_with_estimates_egp'] !== null
        );
        $net = $netComplete ? round((float) $rows->sum('cash_net_known_egp'), 2) : null;
        $cost = $costComplete ? round((float) $rows->sum('service_cost_actual_egp'), 2) : null;
        $margin = $net !== null && $cost !== null ? round($net - $cost, 2) : null;
        $aiRequests = (int) $rows->sum('ai_requests');
        $aiFailedRequests = (int) $rows->sum('ai_failed_requests');
        $aiUnansweredRequests = (int) $rows->sum('ai_unanswered_requests');
        $aiEstimatedRequests = (int) $rows->sum('ai_estimated_requests');
        $aiMeasurementAvailable = $rows->every(
            fn (array $row): bool => (bool) ($row['ai_measurement_available'] ?? true)
        );
        $aiAttempts = $aiRequests + $aiFailedRequests + $aiUnansweredRequests;

        return [
            'students' => $students,
            'enrollments' => $enrollments,
            'coins' => (int) $rows->sum('total_coins'),
            'discount_coins' => (int) $rows->sum('discount_coins'),
            'gross_egp' => round((float) $rows->sum('cash_gross_egp'), 2),
            'net_egp' => $net,
            'ai_requests' => $aiRequests,
            'ai_failed_requests' => $aiFailedRequests,
            'ai_unanswered_requests' => $aiUnansweredRequests,
            'ai_estimated_requests' => $aiEstimatedRequests,
            'ai_cost_complete' => $aiMeasurementAvailable && $aiEstimatedRequests === 0,
            'ai_failure_rate_percentage' => $aiAttempts > 0
                ? round((($aiFailedRequests + $aiUnansweredRequests) / $aiAttempts) * 100, 2)
                : null,
            'ai_tokens' => (int) $rows->sum('ai_tokens'),
            'ai_measurement_available' => $aiMeasurementAvailable,
            'ai_cost_usd' => $aiMeasurementAvailable
                ? round((float) $rows->sum('ai_cost_usd'), 6)
                : null,
            'playback_minutes' => round((float) $rows->sum('playback_minutes'), 2),
            'playback_gb_estimated' => round((float) $rows->sum('playback_gb_estimated'), 4),
            'service_cost_egp' => $cost,
            'margin_egp' => $margin,
            'estimated_cost_egp' => $estimatedComplete
                ? round((float) $rows->sum('service_cost_with_estimates_egp'), 2)
                : null,
            'estimated_margin_egp' => $netComplete && $estimatedComplete
                ? round((float) $rows->sum('estimated_contribution_margin_egp'), 2)
                : null,
            'average_net_per_student_egp' => $students > 0 && $net !== null
                ? round($net / $students, 2)
                : null,
            'average_cost_per_student_egp' => $students > 0 && $cost !== null
                ? round($cost / $students, 2)
                : null,
            'average_net_per_enrollment_egp' => $enrollments > 0 && $net !== null
                ? round($net / $enrollments, 2)
                : null,
            'average_cost_per_enrollment_egp' => $enrollments > 0 && $cost !== null
                ? round($cost / $enrollments, 2)
                : null,
        ] + $this->unitEconomics($net, $cost, $margin);
    }

    /** @return array<string, float|null> */
    private function unitEconomics(?float $net, ?float $cost, ?float $margin): array
    {
        return [
            'cost_to_net_revenue_percentage' => $net !== null && $net > 0 && $cost !== null
                ? round(($cost / $net) * 100, 2)
                : null,
            'contribution_margin_percentage' => $net !== null && $net > 0 && $margin !== null
                ? round(($margin / $net) * 100, 2)
                : null,
        ];
    }

    /** @param Collection<int, Order> $orders */
    private function allocationsFor(Collection $orders): Collection
    {
        if (
            $orders->isEmpty()
            || !Schema::hasTable('wallet_debit_allocations')
            || !Schema::hasTable('wallet_credit_lots')
        ) {
            return collect();
        }

        return WalletDebitAllocation::query()
            ->whereIn('course_order_id', $orders->modelKeys())
            ->with('creditLot.sourceOrder')
            ->get()
            ->groupBy('course_order_id');
    }

    /**
     * @param Collection<int, Order> $orders
     * @param Collection<int, Collection<int, WalletDebitAllocation>> $allocationsByOrder
     * @return array<string, int|float|bool>
     */
    private function cashForOrders(Collection $orders, Collection $allocationsByOrder): array
    {
        $gross = 0.0;
        $estimatedGross = 0.0;
        $netKnown = 0.0;
        $pendingGross = 0.0;
        $allocatedCoins = 0;
        $reconciliationMissing = false;
        $foreignCurrencyAmounts = [];
        $channels = [];

        foreach ($orders as $order) {
            $orderAllocatedCoins = 0;
            foreach ($allocationsByOrder->get($order->id, collect()) as $allocation) {
                $lot = $allocation->creditLot;
                $source = $lot?->sourceOrder;
                $coins = max(0, (int) $allocation->amount);
                $lotCoins = max(0, (int) $lot?->original_amount);
                if (!$source || $coins === 0 || $lotCoins === 0) {
                    continue;
                }

                $orderAllocatedCoins += $coins;
                $allocatedCoins += $coins;
                if ($source->financial_status !== Order::FINANCIAL_SETTLED) {
                    continue;
                }

                $ratio = min(1, $coins / $lotCoins);
                $sourceGross = (float) ($source->gateway_gross_amount ?? $source->final_amount ?? 0);
                $sourceGrossKnown = $source->gateway_gross_amount !== null
                    && $source->gateway_settlement_status !== 'catalog_estimate';
                $attributedGross = $sourceGross * $ratio;
                $method = (string) $source->payment_method;
                $sourceCurrency = strtoupper((string) (
                    $source->gateway_currency
                    ?: (in_array($method, [
                        Order::PAYMENT_METHOD_GOOGLE_PLAY,
                        Order::PAYMENT_METHOD_APP_STORE,
                    ], true) ? 'PENDING' : 'EGP')
                ));
                $channels[$method] ??= [
                    'method' => $method,
                    'label' => match ($method) {
                        Order::PAYMENT_METHOD_KASHIER => 'Kashier',
                        Order::PAYMENT_METHOD_GOOGLE_PLAY => 'Google Play',
                        Order::PAYMENT_METHOD_APP_STORE => 'App Store',
                        default => $method,
                    },
                    'paid_coins' => 0,
                    'gross_egp' => 0.0,
                    'estimated_gross_egp' => 0.0,
                    'gross_complete' => true,
                    'net_known_egp' => 0.0,
                    'pending_settlement_egp' => 0.0,
                    'net_complete' => true,
                    'foreign_currency_amounts' => [],
                ];
                $channels[$method]['paid_coins'] += $coins;
                if ($sourceCurrency === 'PENDING') {
                    // Store catalogue prices are not cash evidence. Until the
                    // provider supplies the settlement currency, do not turn a
                    // local package price into apparent EGP course revenue.
                    $channels[$method]['gross_complete'] = false;
                    $channels[$method]['net_complete'] = false;
                    $reconciliationMissing = true;
                    continue;
                }
                if ($sourceCurrency !== 'EGP') {
                    $foreignCurrencyAmounts[$sourceCurrency] =
                        ($foreignCurrencyAmounts[$sourceCurrency] ?? 0.0) + $attributedGross;
                    $channels[$method]['foreign_currency_amounts'][$sourceCurrency] =
                        ($channels[$method]['foreign_currency_amounts'][$sourceCurrency] ?? 0.0)
                        + $attributedGross;
                    $channels[$method]['net_complete'] = false;
                    $channels[$method]['gross_complete'] = false;
                    $reconciliationMissing = true;
                    continue;
                }
                if ($sourceGrossKnown) {
                    $gross += $attributedGross;
                    $channels[$method]['gross_egp'] += $attributedGross;
                } else {
                    $estimatedGross += $attributedGross;
                    $channels[$method]['estimated_gross_egp'] += $attributedGross;
                    $channels[$method]['gross_complete'] = false;
                }

                if ($source->gateway_net_amount !== null) {
                    $attributedNet = (float) $source->gateway_net_amount * $ratio;
                    $netKnown += $attributedNet;
                    $channels[$method]['net_known_egp'] += $attributedNet;
                } elseif ($source->gateway_fee_amount !== null) {
                    $attributedNet = max(0, $sourceGross - (float) $source->gateway_fee_amount) * $ratio;
                    $netKnown += $attributedNet;
                    $channels[$method]['net_known_egp'] += $attributedNet;
                } else {
                    $pendingGross += $attributedGross;
                    $channels[$method]['pending_settlement_egp'] += $attributedGross;
                    $channels[$method]['net_complete'] = false;
                }
            }

            $missingPaidCoins = max(0, (int) $order->paid_coins - $orderAllocatedCoins);
            if ($missingPaidCoins > 0) {
                $channels['unreconciled'] ??= [
                    'method' => 'unreconciled',
                    'label' => 'مصدر شحن غير مُسوّى',
                    'paid_coins' => 0,
                    'gross_egp' => 0.0,
                    'estimated_gross_egp' => 0.0,
                    'gross_complete' => false,
                    'net_known_egp' => 0.0,
                    'pending_settlement_egp' => 0.0,
                    'net_complete' => false,
                    'foreign_currency_amounts' => [],
                ];
                $channels['unreconciled']['paid_coins'] += $missingPaidCoins;
                $reconciliationMissing = true;
            }
        }

        return [
            'cash_gross_egp' => round($gross, 2),
            'cash_estimated_gross_egp' => round($estimatedGross, 2),
            'cash_gross_complete' => collect($channels)->every(
                fn (array $channel): bool => (bool) $channel['gross_complete']
            ),
            'cash_net_known_egp' => round($netKnown, 2),
            'cash_pending_settlement_egp' => round($pendingGross, 2),
            'cash_net_complete' => $pendingGross < 0.005 && !$reconciliationMissing,
            'allocated_paid_coins' => $allocatedCoins,
            'cash_foreign_currency_amounts' => collect($foreignCurrencyAmounts)
                ->map(fn (float $amount): float => round($amount, 2))
                ->all(),
            'cash_channels' => collect($channels)->map(function (array $channel): array {
                $channel['gross_egp'] = round((float) $channel['gross_egp'], 2);
                $channel['estimated_gross_egp'] = round(
                    (float) $channel['estimated_gross_egp'],
                    2
                );
                $channel['net_known_egp'] = round((float) $channel['net_known_egp'], 2);
                $channel['pending_settlement_egp'] = round(
                    (float) $channel['pending_settlement_egp'],
                    2
                );
                $channel['foreign_currency_amounts'] = collect(
                    $channel['foreign_currency_amounts']
                )->map(fn (float $amount): float => round($amount, 2))->all();

                return $channel;
            })->all(),
        ];
    }
}
