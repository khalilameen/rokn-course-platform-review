<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\FinancialAnomaly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final readonly class FinancialAnomalyService
{
    public function __construct(
        private CourseAccessPlanService $plans,
        private WalletService $wallet,
        private InternalSignalService $internalSignals
    ) {
    }

    public function allowsVariableCostFeatures(CourseEnrollment $enrollment): bool
    {
        if (!Schema::hasTable('financial_anomalies')) {
            // Lightweight test/upgrade schemas may not have reached the new
            // ledger yet. ProductionPreflight blocks a real release in that
            // state, so runtime remains backward-compatible during rollout.
            Log::warning('Variable-cost entitlement checks are unavailable because the anomaly ledger is missing.', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
            ]);

            return true;
        }

        $terms = $this->plans->termsForEnrollment($enrollment);
        $expected = max(0, (int) ($terms['minimum_paid_coins'] ?? 0));
        $actual = $this->wallet->coursePaidContribution(
            (int) $enrollment->user_id,
            (int) $enrollment->course_id
        );
        if ($actual >= $expected) {
            FinancialAnomaly::query()
                ->where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->where('expected_paid_coins', '<=', $actual)
                ->update([
                    'status' => FinancialAnomaly::STATUS_RESOLVED,
                    'actual_paid_coins' => $actual,
                    'resolved_at' => now(),
                    'resolution_note' => 'Auto-resolved after the immutable paid-coin ledger reached the entitlement floor.',
                    'updated_at' => now(),
                ]);

            return !FinancialAnomaly::query()
                ->where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->exists();
        }

        $orderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id ?: 0);
        $wasNewAlert = false;
        $anomaly = DB::transaction(function () use (
            $enrollment,
            $terms,
            $expected,
            $actual,
            $orderId,
            &$wasNewAlert
        ): FinancialAnomaly {
            $query = FinancialAnomaly::query()
                ->where('type', FinancialAnomaly::TYPE_PAID_FLOOR_SHORTFALL);
            $orderId > 0
                ? $query->where('order_id', $orderId)
                : $query->where('enrollment_id', $enrollment->id)->whereNull('order_id');

            $anomaly = $query->lockForUpdate()->first();
            if (!$anomaly) {
                $anomaly = new FinancialAnomaly([
                    'public_id' => (string) Str::uuid(),
                    'order_id' => $orderId ?: null,
                    'type' => FinancialAnomaly::TYPE_PAID_FLOOR_SHORTFALL,
                    'detected_at' => now(),
                ]);
                $wasNewAlert = true;
            } elseif ($anomaly->status !== FinancialAnomaly::STATUS_OPEN) {
                $wasNewAlert = true;
                $anomaly->detected_at = now();
            }

            $anomaly->fill([
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
                'enrollment_id' => $enrollment->id,
                'status' => FinancialAnomaly::STATUS_OPEN,
                'expected_paid_coins' => $expected,
                'actual_paid_coins' => $actual,
                'metadata' => [
                    'plan_code' => $terms['code'] ?? null,
                    'source' => 'entitlement_paid_floor',
                ],
                'resolved_by' => null,
                'resolved_at' => null,
                'resolution_note' => null,
            ])->save();

            if ($wasNewAlert) {
                $occurrence = (string) $anomaly->detected_at?->format('Y-m-d\TH:i:s.uP');
                $this->internalSignals->record(
                    'financial_anomaly.opened',
                    'anomaly:' . $anomaly->public_id . ':opened:'
                        . $occurrence,
                    ['anomaly_id' => (int) $anomaly->id, 'occurrence' => $occurrence],
                    FinancialAnomaly::class,
                    (int) $anomaly->id
                );
            }

            return $anomaly;
        }, 3);

        if ($wasNewAlert) {
            Log::critical('Variable-cost entitlement blocked by a paid-coin shortfall', [
                'anomaly_id' => $anomaly->public_id,
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
                'expected_paid_coins' => $expected,
                'actual_paid_coins' => $actual,
            ]);
        }

        return false;
    }
}
