<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendAiUsageThresholdAlert;
use App\Models\AiUsageEvent;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\BusinessClock;

/**
 * Observes platform-wide AI spend without turning one incident into an outage
 * for every paid learner. Hard enforcement remains scoped to each immutable
 * enrollment plan in AiEntitlementBudgetService.
 */
final class AiPlatformUsageMonitor
{
    public function record(int $eventId): void
    {
        try {
            $event = AiUsageEvent::query()->find($eventId);
            if (!$event || $event->status !== 'completed') {
                return;
            }

            $completedAt = $event->completed_at ?: $event->updated_at ?: now();
            $businessCompletedAt = $completedAt->copy()->utc()->setTimezone(BusinessClock::timezoneName());
            $day = $businessCompletedAt->format('Y-m-d');
            $month = $businessCompletedAt->format('Y-m');
            $metrics = Cache::lock('openrouter:usage-monitor:v2:' . $day, 5)
                ->block(2, fn (): array => $this->refreshMetrics($event, $day, $month));

            $this->alertOnce(
                'daily_requests',
                $day,
                $metrics['daily_requests'],
                $this->threshold('ai_global_daily_request_limit', 'openrouter.global_daily_request_limit')
            );
            $this->alertOnce(
                'daily_tokens',
                $day,
                $metrics['daily_tokens'],
                $this->threshold('ai_global_daily_token_budget', 'openrouter.global_daily_token_budget')
            );
            $this->alertOnce(
                'monthly_tokens',
                $month,
                $metrics['monthly_tokens'],
                $this->threshold('ai_global_monthly_token_budget', 'openrouter.global_monthly_token_budget')
            );
        } catch (\Throwable $exception) {
            // Usage has already been settled. Monitoring must be retriable and
            // observable, never a reason to fail the student's successful call.
            Log::warning('Unable to refresh AI platform usage alerts.', [
                'event_id' => $eventId,
                'error_fingerprint' => hash('sha256', $exception::class.'|'.$exception->getMessage()),
            ]);
        }
    }

    /** @return array{daily_requests:int,daily_tokens:int,monthly_tokens:int} */
    private function refreshMetrics(AiUsageEvent $event, string $day, string $month): array
    {
        [$dayStart, $dayEnd] = BusinessClock::localDayRangeUtc($day);
        $monthStartLocal = BusinessClock::localDate($month . '-01')->startOfMonth();
        $monthStart = $monthStartLocal->utc();
        $monthEnd = $monthStartLocal->addMonth()->utc();
        $eventKey = 'openrouter:usage-monitor:v2:event:' . $event->id;
        $dailyRequestKey = 'openrouter:usage-monitor:v2:requests:' . $day;
        $dailyTokenKey = 'openrouter:usage-monitor:v2:tokens:' . $day;
        $monthlyTokenKey = 'openrouter:usage-monitor:v2:tokens:' . $month;
        $alreadyRecorded = Cache::has($eventKey);

        $dailyRequests = Cache::get($dailyRequestKey);
        $dailyTokens = Cache::get($dailyTokenKey);
        $monthlyTokens = Cache::get($monthlyTokenKey);
        if ($dailyRequests === null || $dailyTokens === null) {
            $daily = AiUsageEvent::query()
                ->where('status', 'completed')
                ->where('completed_at', '>=', $dayStart)
                ->where('completed_at', '<', $dayEnd)
                ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens')
                ->first();
            $dailyRequests = (int) ($daily?->requests ?? 0);
            $dailyTokens = (int) ($daily?->tokens ?? 0);
        } elseif (!$alreadyRecorded) {
            $dailyRequests = (int) $dailyRequests + 1;
            $dailyTokens = (int) $dailyTokens + max(0, (int) $event->total_tokens);
        }
        if ($monthlyTokens === null) {
            $monthlyTokens = (int) AiUsageEvent::query()
                ->where('status', 'completed')
                ->where('completed_at', '>=', $monthStart)
                ->where('completed_at', '<', $monthEnd)
                ->sum('total_tokens');
        } elseif (!$alreadyRecorded) {
            $monthlyTokens = (int) $monthlyTokens + max(0, (int) $event->total_tokens);
        }

        Cache::put($dailyRequestKey, $dailyRequests, $dayEnd);
        Cache::put($dailyTokenKey, $dailyTokens, $dayEnd);
        Cache::put($monthlyTokenKey, $monthlyTokens, $monthEnd);
        Cache::put(
            $eventKey,
            true,
            ($event->completed_at ?: $event->updated_at ?: now())->copy()->addMonths(2)
        );

        return [
            'daily_requests' => $dailyRequests,
            'daily_tokens' => $dailyTokens,
            'monthly_tokens' => $monthlyTokens,
        ];
    }

    private function alertOnce(string $metric, string $period, int $actual, int $threshold): void
    {
        if ($threshold <= 0 || $actual < $threshold) {
            return;
        }
        $key = "openrouter:usage-monitor:v2:alert:{$metric}:{$period}";
        if (!Cache::add($key, true, now()->addMonths(2))) {
            return;
        }

        try {
            SendAiUsageThresholdAlert::dispatch($metric, $period, $actual, $threshold);
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }
    }

    private function threshold(string $field, string $configKey): int
    {
        $deployment = max(0, (int) config($configKey, 0));
        $dashboard = max(0, (int) (Setting::query()->value($field) ?? 0));

        if ($deployment === 0) {
            return $dashboard;
        }

        return $dashboard > 0 ? min($deployment, $dashboard) : $deployment;
    }
}
