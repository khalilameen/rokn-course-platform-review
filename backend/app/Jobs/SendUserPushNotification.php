<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\StudentNotificationPresentationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class SendUserPushNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;
    public int $uniqueFor = 900;
    public array $backoff = [10, 60, 180];

    private ?int $notificationId = null;
    private ?int $userId = null;
    private ?string $titleAr = null;
    private ?string $titleEn = null;
    private ?string $messageAr = null;
    private ?string $messageEn = null;
    private ?string $link = null;

    public function __construct(
        int $notificationOrUserId,
        ?string $titleAr = null,
        ?string $titleEn = null,
        ?string $messageAr = null,
        ?string $messageEn = null,
        ?string $link = null
    ) {
        if ($titleAr === null) {
            $this->notificationId = $notificationOrUserId;
        } else {
            // Rolling-deploy compatibility for jobs queued by the prior
            // release. All newly-created jobs use the inbox notification ID.
            $this->userId = $notificationOrUserId;
            $this->titleAr = $titleAr;
            $this->titleEn = $titleEn;
            $this->messageAr = $messageAr;
            $this->messageEn = $messageEn;
            $this->link = $link;
        }
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        if ($this->notificationId !== null) {
            return 'notification:' . $this->notificationId;
        }

        return 'legacy:' . hash('sha256', implode('|', [
            (string) $this->userId,
            (string) $this->titleAr,
            (string) $this->messageAr,
            (string) $this->link,
        ]));
    }

    public function handle(StudentNotificationPresentationService $presentations): void
    {
        if ($this->notificationId === null) {
            $user = $this->userId
                ? User::query()->active()->find($this->userId)
                : null;
            if ($user) {
                FcmNotificationService::sendToUser(
                    $user,
                    (string) $this->titleAr,
                    (string) $this->titleEn,
                    (string) $this->messageAr,
                    (string) $this->messageEn,
                    $this->link
                );
            }
            return;
        }

        // Claim before the external call. A worker retry therefore provides
        // at-most-once push delivery even if the worker is interrupted after
        // FCM accepts the request. The in-app inbox remains authoritative.
        $claimed = StudentNotification::query()
            ->whereKey($this->notificationId)
            ->whereHas('user', fn ($user) => $user->where('active', true))
            ->whereNull('push_attempted_at')
            ->whereNull('push_failed_at')
            ->update([
                'push_attempted_at' => now(),
                'push_attempts' => DB::raw('push_attempts + 1'),
            ]);

        if ($claimed !== 1) {
            $pendingUserId = StudentNotification::query()
                ->whereKey($this->notificationId)
                ->whereNull('push_attempted_at')
                ->whereNull('push_failed_at')
                ->value('user_id');
            if ($pendingUserId && !User::query()->active()->whereKey($pendingUserId)->exists()) {
                StudentNotification::query()
                    ->whereKey($this->notificationId)
                    ->whereNull('push_attempted_at')
                    ->whereNull('push_failed_at')
                    ->update([
                        'push_failed_at' => now(),
                        'push_failure_code' => 'account_inactive',
                        'updated_at' => now(),
                    ]);
            }
            return;
        }

        $notification = StudentNotification::query()
            ->with(['user.deviceTokens', 'notifiable'])
            ->find($this->notificationId);
        if (!$notification || !$notification->user || !(bool) $notification->user->active) {
            StudentNotification::query()
                ->whereKey($this->notificationId)
                ->whereNull('push_sent_at')
                ->update([
                    'push_failed_at' => now(),
                    'push_failure_code' => 'account_inactive',
                    'updated_at' => now(),
                ]);
            return;
        }

        $presentation = $presentations->for($notification);
        $result = FcmNotificationService::sendToUserDetailed(
            $notification->user,
            (string) $notification->title_ar,
            (string) $notification->title_en,
            (string) $notification->message_ar,
            (string) $notification->message_en,
            $presentation['link'],
            [
                'notification_type' => $presentation['notification_type'],
                'course_id' => $presentation['course_id'],
                'image_url' => $presentation['image_url'],
                'action_label_ar' => $presentation['action_label_ar'],
                'action_label_en' => $presentation['action_label_en'],
                'notification_id' => (string) $notification->id,
                'campaign_id' => (string) $notification->delivery_key,
            ]
        );

        if ($result['delivered']) {
            $notification->forceFill([
                'push_sent_at' => now(),
                'push_failed_at' => null,
                'push_failure_code' => null,
            ])->save();
            return;
        }

        if ($result['retryable']) {
            if ($this->attempts() >= $this->tries) {
                $notification->forceFill([
                    'push_failed_at' => now(),
                    'push_failure_code' => 'provider_retry_exhausted',
                ])->save();
            } else {
                $notification->forceFill(['push_attempted_at' => null])->save();
            }
            throw new \RuntimeException('FCM delivery failed temporarily.');
        }

        $notification->forceFill([
            'push_failed_at' => now(),
            'push_failure_code' => 'not_push_eligible',
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->notificationId === null) return;
        StudentNotification::query()
            ->whereKey($this->notificationId)
            ->whereNull('push_sent_at')
            ->whereNull('push_failed_at')
            ->update([
                'push_failed_at' => now(),
                'push_failure_code' => 'worker_failed',
                'updated_at' => now(),
            ]);
    }
}
