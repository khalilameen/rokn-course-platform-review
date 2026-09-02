<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\User;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverStudentNotificationChunk implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public array $backoff = [15, 60, 180];

    /** @param array<int> $userIds */
    public function __construct(
        private array $userIds,
        private string $deliveryKey,
        private string $notificationType,
        private ?string $notifiableType,
        private ?int $notifiableId,
        private string $titleAr,
        private string $titleEn,
        private string $messageAr,
        private string $messageEn,
        private ?string $link = null,
        private ?string $imageUrl = null,
        private ?string $actionLabelAr = null,
        private ?string $actionLabelEn = null
    ) {
        $this->userIds = array_values(array_unique(array_map('intval', $this->userIds)));
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->deliveryKey . '|' . implode(',', $this->userIds));
    }

    public function handle(): void
    {
        $campaign = Schema::hasTable('notification_campaigns')
            ? NotificationCampaign::query()->where('delivery_key', $this->deliveryKey)->first()
            : null;
        $shouldRetry = false;

        foreach ($this->userIds as $userId) {
            try {
                $identity = [
                    'user_id' => $userId,
                    'delivery_key' => $this->deliveryKey,
                ];
                $notification = DB::transaction(function () use ($campaign, $userId, $identity): ?StudentNotification {
                    $recipient = $campaign && Schema::hasTable('notification_campaign_recipients')
                        ? NotificationCampaignRecipient::query()
                            ->where('notification_campaign_id', $campaign->id)
                            ->where('user_id', $userId)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    if ($recipient) {
                        if (in_array($recipient->status, [
                            NotificationCampaignRecipient::STATUS_INBOX,
                            NotificationCampaignRecipient::STATUS_SKIPPED,
                        ], true)) {
                            return StudentNotification::query()->where($identity)->first();
                        }
                        if (
                            $recipient->status === NotificationCampaignRecipient::STATUS_DELIVERING
                            && $recipient->claimed_at
                            && $recipient->claimed_at->isAfter(now()->subMinutes(15))
                        ) {
                            return null;
                        }
                        $recipient->forceFill([
                            'status' => NotificationCampaignRecipient::STATUS_DELIVERING,
                            'attempts' => (int) $recipient->attempts + 1,
                            'claimed_at' => now(),
                            'resolution_code' => null,
                        ])->save();
                    }

                    // Audience selection happened in the coordinator, potentially
                    // minutes ago. Lock and re-check at the durable side effect so
                    // disabling/deleting an account wins before any inbox is made.
                    $user = User::query()
                        ->whereKey($userId)
                        ->lockForUpdate()
                        ->first();
                    $eligible = $user && NotificationDeliveryPolicy::allowsInbox($user, $this->notificationType);
                    if ($eligible && $campaign?->course_id && $campaign->audience !== 'all') {
                        $ownsCourse = $user->enrollments()
                            ->where('course_id', $campaign->course_id)
                            ->where('is_active', true)
                            ->where(function ($expiry): void {
                                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->exists();
                        $eligible = $campaign->audience === 'enrolled' ? $ownsCourse : !$ownsCourse;
                    }
                    if (!$eligible) {
                        if ($recipient) {
                            $recipient->forceFill([
                                'status' => NotificationCampaignRecipient::STATUS_SKIPPED,
                                'resolution_code' => $user ? 'preference_or_audience_changed' : 'account_missing',
                                'resolved_at' => now(),
                            ])->save();
                        }
                        return null;
                    }

                    $notification = StudentNotification::query()->firstOrCreate($identity, [
                        'notification_type' => $this->notificationType,
                        'notifiable_type' => $this->notifiableType,
                        'notifiable_id' => $this->notifiableId,
                        'title_ar' => $this->titleAr,
                        'title_en' => $this->titleEn,
                        'message_ar' => $this->messageAr,
                        'message_en' => $this->messageEn,
                        'link' => $this->link,
                        'image_url' => $this->imageUrl,
                        'action_label_ar' => $this->actionLabelAr,
                        'action_label_en' => $this->actionLabelEn,
                        'is_read' => false,
                        'read_at' => null,
                    ]);
                    if ($recipient) {
                        $recipient->forceFill([
                            'status' => NotificationCampaignRecipient::STATUS_INBOX,
                            'resolution_code' => null,
                            'resolved_at' => now(),
                        ])->save();
                    }

                    return $notification;
                }, 3);
                if (!$notification) {
                    continue;
                }

                // Dispatching this on every chunk retry is intentional. The push
                // job atomically claims the notification row, so a retry repairs a
                // crash between inbox creation and queue dispatch without sending
                // the same push twice.
                try {
                    SendUserPushNotification::dispatch((int) $notification->id)
                        ->afterCommit();
                } catch (\Throwable $exception) {
                    // The inbox is the durable delivery. The scheduler will pick
                    // up this unattempted push after the queue connection recovers.
                    report($exception);
                }
            } catch (\Throwable $exception) {
                report($exception);
                if ($campaign && Schema::hasTable('notification_campaign_recipients')) {
                    // The delivery transaction rolled its attempt increment
                    // back together with the failed side effect. Record the
                    // failed attempt separately so one poison row eventually
                    // becomes a visible skip instead of stalling forever.
                    $recipient = DB::transaction(function () use ($campaign, $userId) {
                        $locked = NotificationCampaignRecipient::query()
                            ->where('notification_campaign_id', $campaign->id)
                            ->where('user_id', $userId)
                            ->lockForUpdate()
                            ->first();
                        if (!$locked) return null;
                        $locked->forceFill([
                            'attempts' => min(255, (int) $locked->attempts + 1),
                        ])->save();
                        return $locked;
                    }, 3);
                    if ($recipient) {
                        $exhausted = (int) $recipient->attempts >= $this->tries;
                        $recipient->forceFill([
                            'status' => $exhausted
                                ? NotificationCampaignRecipient::STATUS_SKIPPED
                                : NotificationCampaignRecipient::STATUS_PENDING,
                            'claimed_at' => null,
                            'resolved_at' => $exhausted ? now() : null,
                            'resolution_code' => $exhausted
                                ? 'recipient_retry_exhausted'
                                : 'recipient_retry',
                        ])->save();
                        $shouldRetry = $shouldRetry || !$exhausted;
                    }
                }
            }
        }

        if ($campaign) {
            $this->refreshCampaignProgress($campaign);
        }

        // Retry the same chunk after every other recipient has had its turn.
        // Successful rows are idempotent, while a single poison recipient can
        // never stop the rest of the campaign and is skipped after exhaustion.
        if ($shouldRetry) {
            throw new \RuntimeException('One or more campaign recipients need retry.');
        }
    }

    private function refreshCampaignProgress(NotificationCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            // Serialize the derived counter write. Without the campaign lock,
            // an older chunk can overwrite a just-completed status with its
            // stale pre-final count.
            $freshCampaign = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();
            if (!$freshCampaign) return;
            $counts = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->selectRaw("SUM(CASE WHEN status = 'inbox' THEN 1 ELSE 0 END) as inbox_count")
                ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count")
                ->selectRaw("SUM(CASE WHEN status IN ('inbox', 'skipped') THEN 1 ELSE 0 END) as resolved_count")
                ->first();
            $inbox = (int) ($counts?->inbox_count ?? 0);
            $skipped = (int) ($counts?->skipped_count ?? 0);
            $resolved = (int) ($counts?->resolved_count ?? 0);
            $complete = $freshCampaign->selection_finished_at !== null
                && $resolved >= (int) $freshCampaign->recipients_count;

            $freshCampaign->forceFill([
                'inbox_count' => $inbox,
                'skipped_count' => $skipped,
                'resolved_count' => $resolved,
                'status' => $complete ? NotificationCampaign::STATUS_COMPLETED : NotificationCampaign::STATUS_DELIVERING,
                'completed_at' => $complete ? now() : null,
                'failed_at' => null,
                'failure_code' => $complete ? null : $freshCampaign->failure_code,
            ])->save();
        }, 3);
    }

    public function failed(\Throwable $exception): void
    {
        if (!Schema::hasTable('notification_campaigns')) {
            return;
        }

        $campaign = NotificationCampaign::query()->where('delivery_key', $this->deliveryKey)->first();
        if (!$campaign || !Schema::hasTable('notification_campaign_recipients')) {
            return;
        }

        NotificationCampaignRecipient::query()
            ->where('notification_campaign_id', $campaign->id)
            ->whereIn('user_id', $this->userIds)
            ->where('status', NotificationCampaignRecipient::STATUS_DELIVERING)
            ->update([
                'status' => NotificationCampaignRecipient::STATUS_PENDING,
                'claimed_at' => null,
                'resolution_code' => 'worker_retry',
                'updated_at' => now(),
            ]);
        NotificationCampaign::query()->whereKey($campaign->id)->update([
            'failure_code' => 'chunk_' . substr(hash('sha256', $exception::class), 0, 12),
            'updated_at' => now(),
        ]);
    }
}
