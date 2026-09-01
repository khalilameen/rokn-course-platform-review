<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
        $counts['student_notifications'] = $this->pruneStudentNotifications($limit);
        $counts['support_cases'] = $this->pruneSupportCases($limit);
        $counts['notification_campaigns'] = $this->deleteByIds(
            'notification_campaigns',
            fn (Builder $query): Builder => $query
                ->whereIn('status', ['completed', 'failed'])
                ->where(
                    'created_at',
                    '<=',
                    now()->subDays(max(1, (int) config('retention.student_notifications_days', 180)))
                ),
            $limit
        );
        $counts['notification_assets'] = $this->pruneNotificationAssets($limit);
        $counts['social_oauth_attempts'] = $this->deleteByIds(
            'social_oauth_attempts',
            fn (Builder $query): Builder => $query->where(function (Builder $expired): void {
                $expired->where('state_expires_at', '<=', now()->subDay())
                    ->orWhere('completion_expires_at', '<=', now()->subDay());
            }),
            $limit
        );
        $counts['course_chat_turns'] = $this->deleteByIds(
            'course_chat_turns',
            fn (Builder $query): Builder => $query->where('expires_at', '<=', now()),
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

    private function pruneStudentNotifications(int $limit): int
    {
        if (!Schema::hasTable('student_notifications')) {
            return 0;
        }

        $query = DB::table('student_notifications')
            ->where(
                'created_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.student_notifications_days', 180)))
            )
            ->orderBy('id')
            ->limit($limit);
        $columns = Schema::hasColumn('student_notifications', 'image_url')
            ? ['id', 'image_url']
            : ['id'];
        $rows = $query->get($columns);
        if ($rows->isEmpty()) {
            return 0;
        }

        $deleted = DB::table('student_notifications')
            ->whereIn('id', $rows->pluck('id'))
            ->delete();

        if ($columns === ['id']) {
            return $deleted;
        }

        $rows->pluck('image_url')
            ->filter()
            ->unique()
            ->each(function (string $url): void {
                if (DB::table('student_notifications')->where('image_url', $url)->exists()) {
                    return;
                }
                if (
                    Schema::hasTable('notification_campaigns')
                    && DB::table('notification_campaigns')->where('image_url', $url)->exists()
                ) {
                    return;
                }

                $path = parse_url($url, PHP_URL_PATH);
                if (is_string($path) && str_starts_with($path, '/storage/student-notifications/')) {
                    Storage::disk('public')->delete(ltrim(substr($path, strlen('/storage/')), '/'));
                }
            });

        return $deleted;
    }

    private function pruneNotificationAssets(int $limit): int
    {
        if (
            !Schema::hasTable('student_notifications')
            || !Schema::hasColumn('student_notifications', 'image_url')
        ) {
            return 0;
        }

        $disk = Storage::disk('public');
        $deleted = 0;
        foreach (array_slice($disk->files('student-notifications'), 0, $limit) as $path) {
            if (
                DB::table('student_notifications')->where('image_url', 'like', '%' . $path)->exists()
                || (
                    Schema::hasTable('notification_campaigns')
                    && DB::table('notification_campaigns')->where('image_url', 'like', '%' . $path)->exists()
                )
                || $disk->lastModified($path) > now()->subDay()->timestamp
            ) {
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function pruneSupportCases(int $limit): int
    {
        if (!Schema::hasTable('feedback_reports') || !Schema::hasColumn('feedback_reports', 'retention_until')) {
            return 0;
        }
        $ids = DB::table('feedback_reports')
            ->whereIn('status', ['resolved', 'closed', 'dismissed'])
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) return 0;

        $attachments = Schema::hasTable('feedback_attachments')
            ? DB::table('feedback_attachments')->whereIn('feedback_report_id', $ids)->get(['disk', 'path'])
            : collect();
        $deleted = DB::transaction(fn (): int => DB::table('feedback_reports')->whereIn('id', $ids)->delete());
        $attachments->each(fn (object $attachment) => app(\App\Services\StoredFileDeletionService::class)
            ->deleteOrQueue((string) $attachment->disk, (string) $attachment->path));
        return $deleted;
    }
}
