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
            ->where('status', Order::STATUS_APPROVED)
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
                    'ai_requests' => 0, 'ai_failed_requests' => 0, 'ai_tokens' => 0,
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

            return $row;
        })->values();

        $gross = round((float) $rows->sum('cash_gross_egp'), 2);
        $knownNet = round((float) $rows->sum('cash_net_known_egp'), 2);
        $pendingGross = round((float) $rows->sum('cash_pending_settlement_egp'), 2);
        $cashNetComplete = $rows->every(fn (array $row): bool => (bool) $row['cash_net_complete']);
        $serviceCostComplete = $rows->every(
            fn (array $row): bool => (bool) $row['service_cost_complete']
        );
        $serviceCost = $serviceCostComplete
            ? round((float) $rows->sum('service_cost_actual_egp'), 2)
            : null;

        return [
            'rows' => $rows,
            'active_students' => $rows->where('is_active', true)->count(),
            'historical_students' => $rows->count(),
            'grant_students' => $rows->filter(fn (array $row): bool => str_starts_with($row['source'], 'grant'))->count(),
            'code_students' => $rows->filter(fn (array $row): bool => str_contains($row['source'], 'code'))->count(),
            'paid_students' => $rows->where('paid_coins', '>', 0)->count(),
            'total_coins' => (int) $orders->sum('total_coins'),
            'paid_coins' => (int) $orders->sum('paid_coins'),
            'reward_coins' => (int) $orders->sum('reward_coins'),
            'cash_gross_egp' => $gross,
            'cash_net_known_egp' => $knownNet,
            'cash_pending_settlement_egp' => $pendingGross,
            'cash_net_complete' => $cashNetComplete,
            'cash_net_egp' => $cashNetComplete ? $knownNet : null,
            'ai_cost_usd' => $costReport['ai_cost_usd'],
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
            'plan_breakdown' => $rows->groupBy('plan_name')->map(fn (Collection $planRows): array => [
                'students' => $planRows->count(),
                'coins' => (int) $planRows->sum('total_coins'),
                'gross_egp' => round((float) $planRows->sum('cash_gross_egp'), 2),
                'ai_cost_usd' => round((float) $planRows->sum('ai_cost_usd'), 6),
                'service_cost_egp' => $planRows->every(fn (array $row): bool => (bool) $row['service_cost_complete'])
                    ? round((float) $planRows->sum('service_cost_actual_egp'), 2)
                    : null,
                'margin_egp' => $planRows->every(fn (array $row): bool => $row['contribution_margin_egp'] !== null)
                    ? round((float) $planRows->sum('contribution_margin_egp'), 2)
                    : null,
                'estimated_cost_egp' => $planRows->every(fn (array $row): bool => $row['service_cost_with_estimates_egp'] !== null)
                    ? round((float) $planRows->sum('service_cost_with_estimates_egp'), 2)
                    : null,
                'estimated_margin_egp' => $planRows->every(fn (array $row): bool => $row['estimated_contribution_margin_egp'] !== null)
                    ? round((float) $planRows->sum('estimated_contribution_margin_egp'), 2)
                    : null,
            ]),
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
        $netKnown = 0.0;
        $pendingGross = 0.0;
        $allocatedCoins = 0;
        $reconciliationMissing = false;

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
                $attributedGross = $sourceGross * $ratio;
                $gross += $attributedGross;

                if ($source->gateway_net_amount !== null) {
                    $netKnown += (float) $source->gateway_net_amount * $ratio;
                } elseif ($source->gateway_fee_amount !== null) {
                    $netKnown += max(0, $sourceGross - (float) $source->gateway_fee_amount) * $ratio;
                } else {
                    $pendingGross += $attributedGross;
                }
            }

            $missingPaidCoins = max(0, (int) $order->paid_coins - $orderAllocatedCoins);
            if ($missingPaidCoins > 0) {
                $reconciliationMissing = true;
            }
        }

        return [
            'cash_gross_egp' => round($gross, 2),
            'cash_net_known_egp' => round($netKnown, 2),
            'cash_pending_settlement_egp' => round($pendingGross, 2),
            'cash_net_complete' => $pendingGross < 0.005 && !$reconciliationMissing,
            'allocated_paid_coins' => $allocatedCoins,
        ];
    }
}
