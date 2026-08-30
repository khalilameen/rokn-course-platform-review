<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Allocates measurable and invoiced service costs to course learners. */
final class CourseCostReportService
{
    /** @param Collection<int, int> $userIds @return array<string, mixed> */
    public function forCourse(Course $course, Collection $userIds): array
    {
        $userIds = $userIds->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $rows = $userIds->mapWithKeys(fn (int $id): array => [$id => $this->emptyUserCost()]);
        $usdToEgp = (float) (Setting::query()->value('openrouter_usd_to_egp_rate') ?? 0);

        if ($userIds->isNotEmpty() && Schema::hasTable('ai_usage_events')) {
            $hasEgpSnapshots = Schema::hasColumn('ai_usage_events', 'cost_egp');
            $egpSelect = $hasEgpSnapshots
                ? ", SUM(CASE WHEN status = 'completed' THEN COALESCE(cost_egp, 0) ELSE 0 END) as cost_egp, SUM(CASE WHEN status = 'completed' AND cost_usd > 0 AND cost_egp IS NULL THEN cost_usd ELSE 0 END) as unsnapped_cost_usd"
                : ", 0 as cost_egp, SUM(CASE WHEN status = 'completed' THEN cost_usd ELSE 0 END) as unsnapped_cost_usd";
            $ai = DB::table('ai_usage_events')
                ->where('course_id', $course->id)
                ->whereIn('user_id', $userIds)
                ->selectRaw("user_id, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_requests, SUM(CASE WHEN status IN ('failed','cancelled','expired') THEN 1 ELSE 0 END) as failed_requests, SUM(CASE WHEN status = 'completed' THEN total_tokens ELSE 0 END) as total_tokens, SUM(CASE WHEN status = 'completed' THEN cost_usd ELSE 0 END) as cost_usd{$egpSelect}")
                ->groupBy('user_id')
                ->get();
            foreach ($ai as $usage) {
                $row = $rows->get((int) $usage->user_id, $this->emptyUserCost());
                $row['ai_requests'] = (int) $usage->completed_requests;
                $row['ai_failed_requests'] = (int) $usage->failed_requests;
                $row['ai_tokens'] = (int) $usage->total_tokens;
                $row['ai_cost_usd'] = round((float) $usage->cost_usd, 6);
                $unsnappedUsd = (float) $usage->unsnapped_cost_usd;
                $row['ai_cost_egp'] = $unsnappedUsd < 0.0000005
                    ? round((float) $usage->cost_egp, 4)
                    : null;
                $row['ai_cost_egp_estimated'] = $usdToEgp > 0
                    ? round((float) $usage->cost_egp + $unsnappedUsd * $usdToEgp, 4)
                    : $row['ai_cost_egp'];
                $rows->put((int) $usage->user_id, $row);
            }
        }

        $playback = $this->playbackUsage((int) $course->id, null, null, $userIds);
        foreach ($rows as $userId => $row) {
            $usage = $playback['users']->get((int) $userId, ['minutes' => 0.0, 'gb' => 0.0]);
            $row['playback_minutes'] = round((float) $usage['minutes'], 2);
            $row['playback_gb_estimated'] = round((float) $usage['gb'], 4);
            $rows->put($userId, $row);
        }

        $unallocatedPools = [];
        $incompleteFinalPool = false;
        $incompleteEstimatedPool = false;
        if (Schema::hasTable('operating_cost_pools')) {
            $pools = OperatingCostPool::query()
                ->where(function ($query) use ($course): void {
                    $query->whereNull('course_id')->orWhere('course_id', $course->id);
                })
                ->orderBy('period_start')
                ->get();
            foreach ($pools as $pool) {
                $amountEgp = $pool->amountEgp();
                if ($amountEgp === null) {
                    $unallocatedPools[] = "{$pool->name}: سعر التحويل غير موجود";
                    $incompleteFinalPool = $incompleteFinalPool || (bool) $pool->is_final;
                    $incompleteEstimatedPool = $incompleteEstimatedPool || !(bool) $pool->is_final;
                    continue;
                }
                $allocation = $this->poolDriver($pool, (int) $course->id, $userIds);
                if ($allocation['denominator'] <= 0) {
                    $unallocatedPools[] = "{$pool->name}: لا توجد بيانات لمسبب التكلفة";
                    $incompleteFinalPool = $incompleteFinalPool || (bool) $pool->is_final;
                    $incompleteEstimatedPool = $incompleteEstimatedPool || !(bool) $pool->is_final;
                    continue;
                }
                foreach ($rows as $userId => $row) {
                    $share = (float) ($allocation['users']->get((int) $userId, 0))
                        / (float) $allocation['denominator'];
                    $allocated = round($amountEgp * min(1, max(0, $share)), 4);
                    $key = $pool->is_final ? 'allocated_operating_cost_egp' : 'estimated_operating_cost_egp';
                    $row[$key] = round((float) $row[$key] + $allocated, 4);
                    $rows->put($userId, $row);
                }
            }
        }

        foreach ($rows as $userId => $row) {
            $hasUnsnapshottedAiCost = (float) $row['ai_cost_usd'] > 0 && $row['ai_cost_egp'] === null;
            $row['service_cost_complete'] = !$hasUnsnapshottedAiCost && !$incompleteFinalPool;
            $row['service_cost_actual_egp'] = !$row['service_cost_complete']
                ? null
                : round((float) ($row['ai_cost_egp'] ?? 0) + (float) $row['allocated_operating_cost_egp'], 4);
            $row['service_cost_estimate_complete'] = $row['ai_cost_egp_estimated'] !== null
                && !$incompleteFinalPool
                && !$incompleteEstimatedPool;
            $row['service_cost_with_estimates_egp'] = !$row['service_cost_estimate_complete']
                ? null
                : round(
                    (float) $row['ai_cost_egp_estimated']
                    + (float) $row['allocated_operating_cost_egp']
                    + (float) $row['estimated_operating_cost_egp'],
                    4
                );
            $rows->put($userId, $row);
        }

        $complete = $rows->every(fn (array $row): bool => (bool) $row['service_cost_complete']);
        $estimateComplete = $rows->every(
            fn (array $row): bool => (bool) $row['service_cost_estimate_complete']
        );

        return [
            'users' => $rows,
            'openrouter_usd_to_egp_rate' => $usdToEgp > 0 ? $usdToEgp : null,
            'ai_cost_usd' => round((float) $rows->sum('ai_cost_usd'), 6),
            'service_cost_actual_egp' => $complete
                ? round((float) $rows->sum('service_cost_actual_egp'), 4)
                : null,
            'service_cost_with_estimates_egp' => $estimateComplete
                ? round((float) $rows->sum('service_cost_with_estimates_egp'), 4)
                : null,
            'playback_minutes' => round((float) $rows->sum('playback_minutes'), 2),
            'playback_gb_estimated' => round((float) $rows->sum('playback_gb_estimated'), 4),
            'complete' => $complete,
            'estimate_complete' => $estimateComplete,
            'unallocated_pools' => $unallocatedPools,
        ];
    }

    /** @return array<string, int|float|bool|null> */
    private function emptyUserCost(): array
    {
        return [
            'ai_requests' => 0, 'ai_failed_requests' => 0, 'ai_tokens' => 0,
            'ai_cost_usd' => 0.0, 'ai_cost_egp' => 0.0,
            'ai_cost_egp_estimated' => 0.0,
            'playback_minutes' => 0.0, 'playback_gb_estimated' => 0.0,
            'allocated_operating_cost_egp' => 0.0,
            'estimated_operating_cost_egp' => 0.0,
            'service_cost_actual_egp' => 0.0,
            'service_cost_with_estimates_egp' => 0.0,
            'service_cost_complete' => true,
            'service_cost_estimate_complete' => true,
        ];
    }

    /** @return array{users:Collection<int,float>,denominator:float} */
    private function poolDriver(
        OperatingCostPool $pool,
        int $courseId,
        Collection $userIds
    ): array {
        $scopedCourseId = $pool->course_id ? (int) $pool->course_id : null;
        if (in_array($pool->allocation_driver, ['playback_gb', 'playback_minutes'], true)) {
            $numerator = $this->playbackUsage(
                $courseId,
                $pool->period_start,
                $pool->period_end,
                $userIds
            );
            $denominator = $this->playbackUsage(
                $scopedCourseId,
                $pool->period_start,
                $pool->period_end,
                null
            );
            $metric = $pool->allocation_driver === 'playback_gb' ? 'gb' : 'minutes';

            return [
                'users' => $numerator['users']->map(fn (array $row): float => (float) $row[$metric]),
                'denominator' => (float) $denominator[$metric],
            ];
        }

        $query = DB::table('course_enrollments')
            ->where('enrolled_at', '<=', $pool->period_end->copy()->endOfDay())
            ->where(function ($active) use ($pool): void {
                $active->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $pool->period_start->copy()->startOfDay());
            });
        if ($scopedCourseId) $query->where('course_id', $scopedCourseId);
        $denominator = (float) (clone $query)->count();
        $userCounts = (clone $query)
            ->where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn ($value): float => (float) $value);

        return ['users' => $userCounts, 'denominator' => $denominator];
    }

    /**
     * Data volume is an estimate from measured session duration × effective
     * bitrate; invoices remain the actual monetary source of truth.
     *
     * @param Collection<int,int>|null $userIds
     * @return array{users:Collection<int,array{minutes:float,gb:float}>,minutes:float,gb:float}
     */
    private function playbackUsage(
        ?int $courseId,
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        ?Collection $userIds
    ): array {
        if (!Schema::hasTable('playback_sessions') || !Schema::hasTable('course_sections')) {
            return ['users' => collect(), 'minutes' => 0.0, 'gb' => 0.0];
        }
        $query = DB::table('playback_sessions as ps')
            ->join('course_sections as cs', 'cs.id', '=', 'ps.course_section_id')
            ->select([
                'ps.user_id', 'ps.started_playing_at', 'ps.started_at', 'ps.ended_at',
                'ps.last_heartbeat_at', 'ps.duration_seconds', 'ps.buffer_duration_ms',
                'ps.effective_bitrate_kbps', 'ps.effective_quality',
            ]);
        if ($courseId) $query->where('cs.course_id', $courseId);
        if ($start) $query->where('ps.started_at', '>=', $start->copy()->startOfDay());
        if ($end) $query->where('ps.started_at', '<=', $end->copy()->endOfDay());
        if ($userIds !== null) {
            if ($userIds->isEmpty()) return ['users' => collect(), 'minutes' => 0.0, 'gb' => 0.0];
            $query->whereIn('ps.user_id', $userIds);
        }

        $users = collect();
        foreach ($query->get() as $session) {
            $started = $session->started_playing_at ?: $session->started_at;
            $ended = $session->ended_at ?: $session->last_heartbeat_at;
            $seconds = $started && $ended
                ? max(0, min(21600, Carbon::parse($started)->diffInSeconds(Carbon::parse($ended))))
                : 0;
            if ((int) $session->duration_seconds > 0) {
                $seconds = min($seconds, (int) $session->duration_seconds);
            }
            $seconds = max(0, $seconds - (int) floor(((int) $session->buffer_duration_ms) / 1000));
            $bitrate = (int) $session->effective_bitrate_kbps;
            if ($bitrate <= 0) {
                $bitrate = match ((string) $session->effective_quality) {
                    '1080p' => 5000, '720p' => 2800, '480p' => 1400, '360p' => 750,
                    default => 1800,
                };
            }
            $row = $users->get((int) $session->user_id, ['minutes' => 0.0, 'gb' => 0.0]);
            $row['minutes'] += $seconds / 60;
            $row['gb'] += ($seconds * $bitrate * 1000 / 8) / 1_000_000_000;
            $users->put((int) $session->user_id, $row);
        }

        return [
            'users' => $users,
            'minutes' => (float) $users->sum('minutes'),
            'gb' => (float) $users->sum('gb'),
        ];
    }
}
