<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendUserPushNotification;
use App\Models\StudentNotification;
use Illuminate\Console\Command;

final class RetryStalledNotificationPushes extends Command
{
    protected $signature = 'notifications:retry-stalled {--limit=500}';
    protected $description = 'Dispatch untouched pushes and quarantine claims with an unknown provider outcome';

    public function handle(): int
    {
        $remaining = max(1, min(5000, (int) $this->option('limit')));
        $queued = 0;

        $staleClaimed = StudentNotification::query()
            ->whereNull('push_sent_at')
            ->whereNull('push_failed_at')
            ->whereNotNull('push_attempted_at')
            ->where('push_attempted_at', '<=', now()->subMinutes(15))
            ->where('created_at', '>=', now()->subDays(7))
            ->update([
                // A worker may have stopped after FCM accepted the push. A
                // blind replay creates duplicate notifications, so preserve
                // the inbox item and expose the uncertain push to operations.
                'push_failed_at' => now(),
                'push_failure_code' => 'delivery_unknown_after_worker_loss',
                'updated_at' => now(),
            ]);

        StudentNotification::query()
            ->whereNull('push_sent_at')
            ->whereNull('push_failed_at')
            ->whereNull('push_attempted_at')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHas('user', fn ($users) => $users
                ->where('active', true)
                ->where('notifications_status', true)
                ->whereHas('deviceTokens'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($notifications) use (&$remaining, &$queued): bool {
                foreach ($notifications as $notification) {
                    if ($remaining-- <= 0) {
                        return false;
                    }

                    SendUserPushNotification::dispatch((int) $notification->id)
                        ->onQueue((string) config('queue.channels.notifications', 'notifications'));
                    $queued++;
                }

                return $remaining > 0;
            });

        $this->info("Queued {$queued} untouched push job(s); quarantined {$staleClaimed} uncertain delivery claim(s).");

        return self::SUCCESS;
    }
}
