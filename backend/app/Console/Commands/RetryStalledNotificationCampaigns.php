<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class RetryStalledNotificationCampaigns extends Command
{
    protected $signature = 'notifications:retry-campaigns {--limit=50}';
    protected $description = 'Requeue notification campaigns that never completed their durable inbox delivery';

    public function handle(): int
    {
        if (!Schema::hasTable('notification_campaigns')) {
            return self::SUCCESS;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $exhaustedBefore = now()->subMinutes(30);

        // Do not leave an exhausted coordinator looking "in progress" for
        // ever.  Turning it into a durable dead letter makes the affected
        // audience visible to operations and prevents a retry storm.
        NotificationCampaign::query()
            ->where('retry_count', '>=', 3)
            ->whereIn('status', [
                NotificationCampaign::STATUS_QUEUED,
                NotificationCampaign::STATUS_DELIVERING,
            ])
            ->where('updated_at', '<=', $exhaustedBefore)
            ->update([
                'status' => NotificationCampaign::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => 'recovery_exhausted',
                'updated_at' => now(),
            ]);

        $queued = 0;
        $candidates = NotificationCampaign::query()
            ->where('retry_count', '<', 3)
            ->where(function ($query): void {
                $query->where(function ($queued): void {
                    $queued->where('status', NotificationCampaign::STATUS_QUEUED)
                        // The coordinator unique lease is 15 minutes. Waiting
                        // until it expires avoids spending a recovery attempt
                        // merely because the notification queue is busy.
                        ->where('queued_at', '<=', now()->subMinutes(15));
                })->orWhere(function ($delivering): void {
                    $delivering->where('status', NotificationCampaign::STATUS_DELIVERING)
                        ->where('updated_at', '<=', now()->subMinutes(30));
                })->orWhere(function ($failed): void {
                    $failed->where('status', NotificationCampaign::STATUS_FAILED)
                        ->where('failed_at', '<=', now()->subMinutes(15));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $campaign) {
            $claimed = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->where('status', $campaign->status)
                ->where('retry_count', $campaign->retry_count)
                ->update([
                    'status' => NotificationCampaign::STATUS_QUEUED,
                    'retry_count' => $campaign->retry_count + 1,
                    'queued_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'coordinator_finished_at' => null,
                    'completed_at' => null,
                ]);
            if ($claimed !== 1) {
                continue;
            }

            SendStudentNotification::dispatch(
                (string) $campaign->notification_type,
                (array) ($campaign->user_ids ?? []),
                $campaign->notifiable_type,
                $campaign->notifiable_id ? (int) $campaign->notifiable_id : null,
                (string) $campaign->title_ar,
                (string) $campaign->title_en,
                (string) $campaign->message_ar,
                (string) $campaign->message_en,
                $campaign->link,
                (array) ($campaign->exclude_user_ids ?? []),
                (string) $campaign->delivery_key,
                $campaign->course_id ? (int) $campaign->course_id : null,
                (string) $campaign->audience,
                $campaign->image_url,
                $campaign->action_label_ar,
                $campaign->action_label_en
            )->onQueue((string) config('queue.channels.notifications', 'notifications'));
            $queued++;
        }

        $this->info("Queued {$queued} stalled notification campaign(s).");
        return self::SUCCESS;
    }
}
