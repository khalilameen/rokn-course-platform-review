<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverStudentNotificationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
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

    public function handle(): void
    {
        foreach ($this->userIds as $userId) {
            $identity = [
                'user_id' => $userId,
                'delivery_key' => $this->deliveryKey,
            ];
            $notification = DB::transaction(function () use ($userId, $identity): ?StudentNotification {
                // Audience selection happened in the coordinator, potentially
                // minutes ago. Lock and re-check at the durable side effect so
                // disabling/deleting an account wins before any inbox is made.
                $user = User::query()
                    ->whereKey($userId)
                    ->where('role', 'client')
                    ->where('active', true)
                    ->when(
                        in_array($this->notificationType, ['course_promotion', 'admin_broadcast'], true),
                        fn ($query) => $query->where('marketing_notifications_enabled', true)
                    )
                    ->lockForUpdate()
                    ->first(['id']);
                if (!$user) {
                    return null;
                }

                return StudentNotification::query()->firstOrCreate($identity, [
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
        }

        if (Schema::hasTable('notification_campaigns')) {
            // Derive progress from the durable inbox instead of incrementing a
            // counter that can drift when a worker stops after an insert.
            $inboxCount = StudentNotification::query()
                ->where('delivery_key', $this->deliveryKey)
                ->count();
            NotificationCampaign::query()
                ->where('delivery_key', $this->deliveryKey)
                ->update(['inbox_count' => $inboxCount]);
            NotificationCampaign::query()
                ->where('delivery_key', $this->deliveryKey)
                ->whereNotNull('coordinator_finished_at')
                ->whereColumn('inbox_count', '>=', 'recipients_count')
                ->update([
                    'status' => NotificationCampaign::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        if (Schema::hasTable('notification_campaigns')) {
            NotificationCampaign::query()
                ->where('delivery_key', $this->deliveryKey)
                ->update([
                    'status' => NotificationCampaign::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'chunk_' . substr(hash('sha256', $exception::class), 0, 12),
                ]);
        }
    }
}
