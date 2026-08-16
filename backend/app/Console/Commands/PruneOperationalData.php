<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneOperationalData extends Command
{
    protected $signature = 'data:prune-operational {--limit=5000 : Maximum rows per table per run}';
    protected $description = 'Bound privacy-sensitive and high-volume operational tables without touching financial ledgers.';

    public function handle(): int
    {
        $limit = max(100, min(20000, (int) $this->option('limit')));
        $counts = [];

        $counts['client_events'] = $this->deleteByIds(
            'client_events',
            fn (Builder $query): Builder => $query->where(
                'received_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.client_events_days', 30)))
            ),
            $limit
        );
        $counts['product_events'] = $this->deleteByIds(
            'product_events',
            fn (Builder $query): Builder => $query->where(
                'received_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.product_events_days', 180)))
            ),
            $limit
        );
        $counts['playback_completed'] = $this->deleteByIds(
            'playback_sessions',
            fn (Builder $query): Builder => $query->whereNotNull('ended_at')->where(
                'ended_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.playback_completed_days', 90)))
            ),
            $limit
        );
        $counts['playback_abandoned'] = $this->deleteByIds(
            'playback_sessions',
            fn (Builder $query): Builder => $query->whereNull('ended_at')->where(
                'started_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.playback_abandoned_days', 30)))
            ),
            $limit
        );
        $counts['playback_metric_rollups'] = $this->deleteByIds(
            'playback_metric_rollups',
            fn (Builder $query): Builder => $query->where(
                'bucket_start',
                '<=',
                now()->subDays(max(30, (int) config('retention.playback_metric_rollups_days', 400)))
            ),
            $limit
        );
        $counts['student_notifications'] = $this->deleteByIds(
            'student_notifications',
            fn (Builder $query): Builder => $query->where(
                'created_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.student_notifications_days', 180)))
            ),
            $limit
        );
        $counts['admin_audit_logs'] = $this->deleteByIds(
            'admin_audit_logs',
            fn (Builder $query): Builder => $query->where(
                'occurred_at',
                '<=',
                now()->subDays(max(30, (int) config('retention.admin_audit_days', 730)))
            ),
            $limit
        );
        $counts['visitors'] = $this->deleteByIds(
            'visitors',
            fn (Builder $query): Builder => $query->where(
                'visited_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.visitors_days', 90)))
            ),
            $limit
        );

        $this->pseudonymizeVisitors($limit);
        $this->info(collect($counts)->map(fn (int $count, string $table): string => "{$table}={$count}")->implode(' '));

        return self::SUCCESS;
    }

    /** @param callable(Builder):Builder $scope */
    private function deleteByIds(string $table, callable $scope, int $limit): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $ids = $scope(DB::table($table))->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($table)->whereIn('id', $ids)->delete();
    }

    private function pseudonymizeVisitors(int $limit): void
    {
        if (!Schema::hasTable('visitors')) {
            return;
        }

        $secret = (string) config('app.key');
        if ($secret === '') {
            return;
        }

        DB::table('visitors')
            ->whereNotNull('ip_address')
            ->whereRaw('LENGTH(ip_address) <> 64')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'ip_address'])
            ->each(function (object $visitor) use ($secret): void {
                DB::table('visitors')->where('id', $visitor->id)->update([
                    'ip_address' => hash_hmac('sha256', (string) $visitor->ip_address, $secret),
                    'user_agent' => null,
                ]);
            });
    }
}
