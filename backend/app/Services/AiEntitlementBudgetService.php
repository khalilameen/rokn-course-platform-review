<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiPlanLimitReachedException;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\CourseEnrollment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final readonly class AiEntitlementBudgetService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private FinancialAnomalyService $financialRisk,
        private AiPlatformUsageMonitor $platformUsage
    ) {
    }

    public function reserve(
        CourseEnrollment $enrollment,
        string $feature,
        int $estimatedTokens,
        string $model,
        ?string $requestId = null
    ): ?AiUsageEvent {
        if (!in_array($feature, AiEntitlementUsage::FEATURES, true)) {
            throw new \InvalidArgumentException('Unknown metered AI feature.');
        }

        return DB::transaction(function () use (
            $enrollment,
            $feature,
            $estimatedTokens,
            $model,
            $requestId
        ): ?AiUsageEvent {
            $lockedEnrollment = CourseEnrollment::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->id);
            if (!$lockedEnrollment->isActive()) {
                throw new AiPlanLimitReachedException('The course entitlement is not active.');
            }
            if (!$this->financialRisk->allowsVariableCostFeatures($lockedEnrollment)) {
                throw new AiPlanLimitReachedException('This enrollment is under financial review.');
            }

            if ($requestId) {
                $existing = AiUsageEvent::query()
                    ->where('request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (
                        (int) $existing->enrollment_id !== (int) $lockedEnrollment->id
                        || (string) $existing->feature !== $feature
                    ) {
                        throw new \UnexpectedValueException('AI request identity conflict.');
                    }

                    return $existing;
                }
            }

            $terms = $this->accessPlans->termsForEnrollment($lockedEnrollment);
            if (!$terms) {
                return null;
            }
            $planId = $lockedEnrollment->access_plan_id
                ? (int) $lockedEnrollment->access_plan_id
                : null;

            // The unique key selects one aggregate; lock it before reserving.
            $now = now();
            DB::table('ai_entitlement_usages')->insertOrIgnore([
                'enrollment_id' => $lockedEnrollment->id,
                'access_plan_id' => $planId,
                'feature' => $feature,
                'used_requests' => 0,
                'reserved_requests' => 0,
                'used_tokens' => 0,
                'reserved_tokens' => 0,
                'used_cost_usd' => '0.000000',
                'reserved_cost_usd' => '0.000000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $usage = AiEntitlementUsage::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('feature', $feature)
                ->lockForUpdate()
                ->firstOrFail();
            $this->reclaimExpiredReservations($usage);
            $usage->refresh();

            $estimatedTokens = max(1, $estimatedTokens);
            $isChat = $feature === AiEntitlementUsage::FEATURE_COURSE_CHAT;
            $isFollowup = $feature === AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP;
            $tokenBudget = (int) ($isChat
                ? ($terms['chat_token_budget'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_token_budget'] ?? 0)
                    : ($terms['project_feedback_token_budget'] ?? 0)));
            $costBudgetMicros = $this->toUsdMicros($isChat
                ? ($terms['ai_budget_usd'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_budget_usd'] ?? 0)
                    : ($terms['project_feedback_budget_usd'] ?? 0)));
            $reserveCostMicros = max(1, $this->toUsdMicros($isChat
                ? ($terms['request_reserve_usd'] ?? 0)
                : ($isFollowup
                    ? ($terms['project_followup_reserve_usd'] ?? 0)
                    : ($terms['project_feedback_reserve_usd'] ?? 0))));
            $featureAllowed = $isChat
                ? (bool) ($terms['chat_enabled'] ?? false)
                : ($isFollowup
                    ? ($terms['project_feedback_level'] ?? null) === 'enhanced'
                        && (int) ($terms['project_followup_message_limit'] ?? 0) > 0
                    : in_array($terms['project_feedback_level'] ?? null, ['report', 'enhanced'], true));
            $requestLimit = $isChat
                ? (int) ($terms['chat_message_limit'] ?? 0)
                : ($isFollowup ? (int) ($terms['project_followup_message_limit'] ?? 0) : null);

            if (
                !$featureAllowed
                || (
                    $requestLimit !== null
                    && $usage->used_requests + $usage->reserved_requests + 1
                        > $requestLimit
                )
                || $usage->used_tokens + $usage->reserved_tokens + $estimatedTokens > $tokenBudget
                || $this->toUsdMicros($usage->used_cost_usd)
                    + $this->toUsdMicros($usage->reserved_cost_usd)
                    + $reserveCostMicros > $costBudgetMicros
            ) {
                throw new AiPlanLimitReachedException('The selected plan AI budget is exhausted.');
            }

            $usage->forceFill([
                'access_plan_id' => $planId,
                'reserved_requests' => $usage->reserved_requests + 1,
                'reserved_tokens' => $usage->reserved_tokens + $estimatedTokens,
                'reserved_cost_usd' => $this->formatUsdMicros(
                    $this->toUsdMicros($usage->reserved_cost_usd) + $reserveCostMicros
                ),
            ])->save();

            return AiUsageEvent::create([
                'request_id' => $requestId ?: (string) Str::uuid(),
                'enrollment_id' => $lockedEnrollment->id,
                'access_plan_id' => $planId,
                'user_id' => $lockedEnrollment->user_id,
                'course_id' => $lockedEnrollment->course_id,
                'feature' => $feature,
                'model' => $model,
                'status' => 'reserved',
                'reserved_tokens' => $estimatedTokens,
                'reserved_cost_usd' => $this->formatUsdMicros($reserveCostMicros),
                'reservation_expires_at' => now()->addSeconds($this->reservationTtlSeconds()),
            ]);
        }, 3);
    }

    public function settle(?AiUsageEvent $event, array $providerResult): void
    {
        if (!$event) {
            return;
        }
        $didSettle = false;
        DB::transaction(function () use ($event, $providerResult, &$didSettle): void {
            $lockedEvent = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$lockedEvent) {
                return;
            }
            if ($lockedEvent->status !== 'reserved') {
                return;
            }
            $usage = AiEntitlementUsage::query()
                ->lockForUpdate()
                ->where('enrollment_id', $lockedEvent->enrollment_id)
                ->where('feature', $lockedEvent->feature)
                ->first();
            if (!$usage) {
                $lockedEvent->forceFill([
                    'status' => 'failed',
                    'metadata' => ['reason' => 'missing_entitlement_aggregate'],
                    'completed_at' => now(),
                ])->save();
                return;
            }
            $providerTotal = max(0, (int) data_get($providerResult, 'usage.total_tokens', 0));
            $providerCostMicros = max(
                0,
                $this->toUsdMicros(data_get($providerResult, 'usage.cost', 0))
            );
            $usageFacts = data_get($providerResult, 'usage', []);
            $providerCostWasReported = data_get($providerResult, 'usage.cost_reported');
            if (!is_bool($providerCostWasReported)) {
                $providerCostWasReported = is_array($usageFacts)
                    && array_key_exists('cost', $usageFacts)
                    && is_numeric($usageFacts['cost']);
            }
            // Missing provider usage settles against the reservation.
            $total = $providerTotal > 0 ? $providerTotal : (int) $lockedEvent->reserved_tokens;
            $costMicros = $providerCostWasReported
                ? $providerCostMicros
                : $this->toUsdMicros($lockedEvent->reserved_cost_usd);

            $usage->forceFill([
                'reserved_requests' => max(0, $usage->reserved_requests - 1),
                'reserved_tokens' => max(0, $usage->reserved_tokens - $lockedEvent->reserved_tokens),
                'reserved_cost_usd' => $this->formatUsdMicros(max(
                    0,
                    $this->toUsdMicros($usage->reserved_cost_usd)
                        - $this->toUsdMicros($lockedEvent->reserved_cost_usd)
                )),
                'used_requests' => $usage->used_requests + 1,
                'used_tokens' => $usage->used_tokens + $total,
                'used_cost_usd' => $this->formatUsdMicros(
                    $this->toUsdMicros($usage->used_cost_usd) + $costMicros
                ),
            ])->save();
            $metadata = is_array($lockedEvent->metadata) ? $lockedEvent->metadata : [];
            $metadata['token_usage_source'] = $providerTotal > 0
                ? 'provider'
                : 'reservation_fallback';
            $metadata['cost_usage_source'] = $providerCostWasReported
                ? 'provider'
                : 'reservation_fallback';
            $metadata['usage_source'] = $providerTotal > 0 && $providerCostWasReported
                ? 'provider'
                : 'reservation_fallback';
            $acceptedResponse = trim((string) data_get($providerResult, 'message', ''));
            if ($acceptedResponse !== '') {
                // The accepted text enables a safe idempotent replay. The
                // provider envelope and failed output are never persisted.
                $metadata['accepted_response'] = mb_substr($acceptedResponse, 0, 12000);
            }
            $requestContext = data_get($providerResult, 'request_context');
            if (is_array($requestContext)) {
                $metadata['request_context'] = array_filter([
                    'question_hash' => isset($requestContext['question_hash'])
                        ? substr((string) $requestContext['question_hash'], 0, 64)
                        : null,
                    'lesson_id' => isset($requestContext['lesson_id'])
                        ? max(0, (int) $requestContext['lesson_id'])
                        : null,
                    'language' => isset($requestContext['language'])
                        ? substr((string) $requestContext['language'], 0, 12)
                        : null,
                    'prompt_version' => isset($requestContext['prompt_version'])
                        ? substr((string) $requestContext['prompt_version'], 0, 64)
                        : null,
                ], static fn ($value): bool => $value !== null && $value !== '');
            }
            $egpFacts = [];
            if (Schema::hasColumn('ai_usage_events', 'cost_egp')) {
                $fxRate = max(0, (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0));
                if ($fxRate > 0) {
                    $egpFacts = [
                        'fx_rate_to_egp' => number_format($fxRate, 4, '.', ''),
                        'cost_egp' => number_format(($costMicros / 1_000_000) * $fxRate, 6, '.', ''),
                    ];
                }
            }
            $lockedEvent->forceFill([
                'status' => 'completed',
                'prompt_tokens' => max(0, (int) data_get($providerResult, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($providerResult, 'usage.completion_tokens', 0)),
                'total_tokens' => $total,
                'cost_usd' => $this->formatUsdMicros($costMicros),
                'provider_request_id' => data_get($providerResult, 'provider_request_id'),
                'metadata' => $metadata,
                'completed_at' => now(),
            ] + $egpFacts)->save();
            $didSettle = true;
        }, 3);

        if ($didSettle) {
            // Monitoring is intentionally outside the entitlement transaction.
            // A reporting outage must never turn a successful paid AI request
            // into an application failure or hold its reservation open.
            $this->platformUsage->record($event->id);
        }
    }

    public function release(?AiUsageEvent $event, ?string $reason = null): void
    {
        if (!$event) {
            return;
        }
        DB::transaction(function () use ($event, $reason): void {
            $lockedEvent = AiUsageEvent::query()->lockForUpdate()->find($event->id);
            if (!$lockedEvent) {
                return;
            }
            if ($lockedEvent->status !== 'reserved') {
                return;
            }
            $usage = AiEntitlementUsage::query()
                ->lockForUpdate()
                ->where('enrollment_id', $lockedEvent->enrollment_id)
                ->where('feature', $lockedEvent->feature)
                ->first();
            if ($usage) {
                $usage->forceFill([
                    'reserved_requests' => max(0, $usage->reserved_requests - 1),
                    'reserved_tokens' => max(0, $usage->reserved_tokens - $lockedEvent->reserved_tokens),
                    'reserved_cost_usd' => $this->formatUsdMicros(max(
                        0,
                        $this->toUsdMicros($usage->reserved_cost_usd)
                            - $this->toUsdMicros($lockedEvent->reserved_cost_usd)
                    )),
                ])->save();
            }
            $metadata = is_array($lockedEvent->metadata) ? $lockedEvent->metadata : [];
            if ($reason) {
                $metadata['reason'] = preg_match('/^[a-z0-9_:-]{1,64}$/i', $reason)
                    ? strtolower($reason)
                    : 'request_failed';
            }
            $lockedEvent->forceFill([
                'status' => 'failed',
                'metadata' => $metadata ?: null,
                'completed_at' => now(),
            ])->save();
        }, 3);
    }

    /** A repurchase resets aggregates but retains immutable usage events. */
    public function resetForNewPurchase(CourseEnrollment $enrollment): void
    {
        if (
            !Schema::hasTable('ai_entitlement_usages')
            || !Schema::hasTable('ai_usage_events')
        ) {
            return;
        }

        $this->cancelOutstandingReservations($enrollment, 'entitlement_replaced');

        DB::transaction(function () use ($enrollment): void {
            CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            AiEntitlementUsage::query()
                ->where('enrollment_id', $enrollment->id)
                ->delete();
        }, 3);
    }

    public function cancelOutstandingReservations(
        CourseEnrollment $enrollment,
        string $reason
    ): int {
        if (
            !Schema::hasTable('ai_entitlement_usages')
            || !Schema::hasTable('ai_usage_events')
        ) {
            return 0;
        }

        return DB::transaction(function () use ($enrollment, $reason): int {
            CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $usages = AiEntitlementUsage::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('feature');
            $reserved = AiUsageEvent::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            foreach ($reserved->groupBy('feature') as $feature => $events) {
                $usage = $usages->get($feature);
                if (!$usage) {
                    continue;
                }
                $costMicros = $events->sum(fn (AiUsageEvent $event): int =>
                    $this->toUsdMicros($event->reserved_cost_usd)
                );
                $usage->forceFill([
                    'reserved_requests' => max(0, $usage->reserved_requests - $events->count()),
                    'reserved_tokens' => max(0, $usage->reserved_tokens - (int) $events->sum('reserved_tokens')),
                    'reserved_cost_usd' => $this->formatUsdMicros(max(
                        0,
                        $this->toUsdMicros($usage->reserved_cost_usd) - $costMicros
                    )),
                ])->save();
            }

            foreach ($reserved as $event) {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $metadata['reason'] = substr($reason, 0, 180);
                $event->forceFill([
                    'status' => 'cancelled',
                    'metadata' => $metadata,
                    'completed_at' => now(),
                ])->save();
            }

            return $reserved->count();
        }, 3);
    }

    public function releaseExpiredReservations(int $limit = 500): int
    {
        if (
            !Schema::hasTable('ai_entitlement_usages')
            || !Schema::hasTable('ai_usage_events')
            || !Schema::hasColumn('ai_usage_events', 'reservation_expires_at')
        ) {
            return 0;
        }

        $leaseStartedBefore = now()->subSeconds($this->reservationTtlSeconds());
        $pairs = DB::table('ai_usage_events')
            ->select(['enrollment_id', 'feature'])
            ->where('status', 'reserved')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->where('created_at', '<=', $leaseStartedBefore)
            ->distinct()
            ->orderBy('enrollment_id')
            ->limit(max(1, min(5000, $limit)))
            ->get();
        $released = 0;

        foreach ($pairs as $pair) {
            $released += DB::transaction(function () use ($pair): int {
                $usage = AiEntitlementUsage::query()
                    ->where('enrollment_id', $pair->enrollment_id)
                    ->where('feature', $pair->feature)
                    ->lockForUpdate()
                    ->first();
                if (!$usage) {
                    return AiUsageEvent::query()
                        ->where('enrollment_id', $pair->enrollment_id)
                        ->where('feature', $pair->feature)
                        ->where('status', 'reserved')
                        ->where('reservation_expires_at', '<=', now())
                        ->update([
                            'status' => 'expired',
                            'metadata' => json_encode(['reason' => 'missing_entitlement_aggregate']),
                            'completed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                return $this->reclaimExpiredReservations($usage);
            }, 3);
        }

        return $released;
    }

    private function reclaimExpiredReservations(AiEntitlementUsage $usage): int
    {
        $leaseStartedBefore = now()->subSeconds($this->reservationTtlSeconds());
        $expired = AiUsageEvent::query()
            ->where('enrollment_id', $usage->enrollment_id)
            ->where('feature', $usage->feature)
            ->where('status', 'reserved')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->where('created_at', '<=', $leaseStartedBefore)
            ->lockForUpdate()
            ->get();
        if ($expired->isEmpty()) {
            return 0;
        }

        $reservedTokens = (int) $expired->sum('reserved_tokens');
        $reservedCostMicros = $expired->sum(fn (AiUsageEvent $event): int =>
            $this->toUsdMicros($event->reserved_cost_usd)
        );
        $usage->forceFill([
            'reserved_requests' => max(0, $usage->reserved_requests - $expired->count()),
            'reserved_tokens' => max(0, $usage->reserved_tokens - $reservedTokens),
            'reserved_cost_usd' => $this->formatUsdMicros(max(
                0,
                $this->toUsdMicros($usage->reserved_cost_usd) - $reservedCostMicros
            )),
        ])->save();

        foreach ($expired as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $metadata['reason'] = 'reservation_expired';
            $event->forceFill([
                'status' => 'expired',
                'metadata' => $metadata,
                'completed_at' => now(),
            ])->save();
        }

        return $expired->count();
    }

    /** @param int|float|string|null $value */
    private function toUsdMicros($value): int
    {
        return max(0, (int) round(((float) $value) * 1_000_000));
    }

    private function formatUsdMicros(int $micros): string
    {
        return number_format(max(0, $micros) / 1_000_000, 6, '.', '');
    }

    private function reservationTtlSeconds(): int
    {
        return max(
            60,
            (int) config('course_plans.ai_reservation_ttl_seconds', 120),
            // Expiry is a recovery lease, not a provider deadline. Keep a
            // full minute for settlement after the longest permitted request
            // so the sweeper cannot release a request that is still billable.
            (int) config('openrouter.timeout_seconds', 45) + 60
        );
    }
}
