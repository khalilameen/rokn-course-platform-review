<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\NotificationPushDelivery;
use App\Services\FcmNotificationService;
use App\Services\NotificationDeliveryPolicy;
use App\Services\StudentNotificationPresentationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        if (Schema::hasTable('notification_push_deliveries')) {
            $this->handlePerDeviceDelivery($presentations);
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

        if ($result['accepted']) {
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
        if (Schema::hasTable('notification_push_deliveries')) {
            NotificationPushDelivery::query()
                ->where('student_notification_id', $this->notificationId)
                ->where('status', NotificationPushDelivery::STATUS_RETRYABLE)
                ->update([
                    'status' => NotificationPushDelivery::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'provider_retry_exhausted',
                    'updated_at' => now(),
                ]);
            $this->refreshNotificationOutcome();
            return;
        }
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

    private function handlePerDeviceDelivery(StudentNotificationPresentationService $presentations): void
    {
        $notification = StudentNotification::query()
            ->with(['user.deviceTokens', 'notifiable'])
            ->find($this->notificationId);
        if (!$notification || !$notification->user) return;
        $user = $notification->user;
        $hasDeviceLedger = NotificationPushDelivery::query()
            ->where('student_notification_id', $notification->id)
            ->exists();
        if (!$hasDeviceLedger && $notification->push_attempted_at !== null) {
            // A notification completed or became uncertain before the per-device
            // ledger migration. Its provider call cannot be reconstructed safely.
            return;
        }
        if (!NotificationDeliveryPolicy::allowsPush($user, (string) $notification->notification_type)) {
            NotificationPushDelivery::query()
                ->where('student_notification_id', $notification->id)
                ->whereIn('status', [
                    NotificationPushDelivery::STATUS_PENDING,
                    NotificationPushDelivery::STATUS_RETRYABLE,
                ])
                ->update([
                    'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                    'failed_at' => now(),
                    'failure_code' => (bool) $user->active ? 'preference_disabled' : 'account_inactive',
                    'updated_at' => now(),
                ]);
            $notification->forceFill([
                'push_failed_at' => now(),
                'push_failure_code' => (bool) $user->active
                    ? 'preference_disabled'
                    : 'account_inactive',
            ])->save();
            return;
        }

        $tokens = $user->deviceTokens->filter(
            fn ($token): bool => trim((string) $token->device_token) !== ''
        )->values();
        if ($tokens->isEmpty()) {
            NotificationPushDelivery::query()
                ->where('student_notification_id', $notification->id)
                ->whereIn('status', [
                    NotificationPushDelivery::STATUS_PENDING,
                    NotificationPushDelivery::STATUS_RETRYABLE,
                ])
                ->update([
                    'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                    'failed_at' => now(),
                    'failure_code' => 'token_unbound',
                    'updated_at' => now(),
                ]);
            $notification->forceFill([
                'push_failed_at' => now(),
                'push_failure_code' => 'no_registered_device',
            ])->save();
            return;
        }

        foreach ($tokens as $token) {
            NotificationPushDelivery::query()->firstOrCreate([
                'student_notification_id' => $notification->id,
                'user_device_token_id' => $token->id,
            ], [
                'token_fingerprint' => hash('sha256', (string) $token->device_token),
                'device_os' => $token->device_os ?: $token->device_type,
                'status' => NotificationPushDelivery::STATUS_PENDING,
            ]);
        }
        $currentTokenIds = $tokens->pluck('id')->map(fn ($id): int => (int) $id)->all();
        NotificationPushDelivery::query()
            ->where('student_notification_id', $notification->id)
            ->whereNotIn('user_device_token_id', $currentTokenIds)
            ->whereIn('status', [
                NotificationPushDelivery::STATUS_PENDING,
                NotificationPushDelivery::STATUS_RETRYABLE,
            ])
            ->update([
                'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                'failed_at' => now(),
                'failure_code' => 'token_rotated',
                'updated_at' => now(),
            ]);

        $presentation = $presentations->for($notification);
        $retryNeeded = false;
        $deliveries = NotificationPushDelivery::query()
            ->where('student_notification_id', $notification->id)
            ->whereIn('status', [
                NotificationPushDelivery::STATUS_PENDING,
                NotificationPushDelivery::STATUS_RETRYABLE,
            ])
            ->orderBy('id')
            ->get();
        foreach ($deliveries as $delivery) {
            $token = $tokens->firstWhere('id', $delivery->user_device_token_id);
            if (!$token || !hash_equals(
                (string) $delivery->token_fingerprint,
                hash('sha256', (string) $token->device_token)
            )) {
                $delivery->forceFill([
                    'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                    'failed_at' => now(),
                    'failure_code' => 'token_rotated',
                ])->save();
                continue;
            }

            // The relation was loaded before this loop. A login to another
            // account, logout, token rotation, or opt-out may have won while
            // this job waited in the queue. Resolve the mutable ownership and
            // consent again at the provider boundary instead of sending from
            // a stale Eloquent snapshot.
            $currentUser = User::query()->find($user->id);
            $currentToken = UserDeviceToken::query()
                ->whereKey($token->id)
                ->where('user_id', $user->id)
                ->where('device_token', (string) $token->device_token)
                ->first();
            if (!$currentUser
                || !NotificationDeliveryPolicy::allowsPush(
                    $currentUser,
                    (string) $notification->notification_type
                )
                || !$currentToken) {
                $delivery->forceFill([
                    'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                    'failed_at' => now(),
                    'failure_code' => $currentToken ? 'preference_disabled' : 'token_unbound',
                ])->save();
                continue;
            }
            if ((int) $delivery->attempts >= $this->tries) {
                $delivery->forceFill([
                    'status' => NotificationPushDelivery::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'provider_retry_exhausted',
                ])->save();
                continue;
            }

            $claimed = NotificationPushDelivery::query()
                ->whereKey($delivery->id)
                ->whereIn('status', [
                    NotificationPushDelivery::STATUS_PENDING,
                    NotificationPushDelivery::STATUS_RETRYABLE,
                ])
                ->update([
                    'status' => NotificationPushDelivery::STATUS_DISPATCHING,
                    'attempts' => DB::raw('attempts + 1'),
                    'attempted_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed !== 1) continue;

            $result = FcmNotificationService::sendToDeviceDetailed(
                $currentUser,
                $currentToken,
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
                    'channel_id' => $this->channelFor((string) $presentation['notification_type']),
                ]
            );
            if ($result['accepted']) {
                NotificationPushDelivery::query()->whereKey($delivery->id)->update([
                    'status' => NotificationPushDelivery::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'updated_at' => now(),
                ]);
                continue;
            }
            if ($result['unknown']) {
                NotificationPushDelivery::query()->whereKey($delivery->id)->update([
                    'status' => NotificationPushDelivery::STATUS_UNKNOWN,
                    'failed_at' => now(),
                    'failure_code' => $result['failure_code'] ?: 'provider_outcome_unknown',
                    'updated_at' => now(),
                ]);
                continue;
            }
            if ($result['retryable']) {
                NotificationPushDelivery::query()->whereKey($delivery->id)->update([
                    'status' => NotificationPushDelivery::STATUS_RETRYABLE,
                    'failed_at' => null,
                    'failure_code' => $result['failure_code'],
                    'updated_at' => now(),
                ]);
                $retryNeeded = true;
                continue;
            }

            NotificationPushDelivery::query()->whereKey($delivery->id)->update([
                'status' => NotificationPushDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => $result['failure_code'] ?: 'provider_rejected',
                'updated_at' => now(),
            ]);
            if ($result['failure_code'] === 'token_unregistered') {
                // FCM rejected the value that was sent, not the mutable token
                // row. An installation can rotate that row while the request
                // is in flight; never delete its new valid value by stale ID.
                $user->deviceTokens()
                    ->whereKey($token->id)
                    ->where('device_token', (string) $token->device_token)
                    ->delete();
            }
        }

        $this->refreshNotificationOutcome();
        if ($retryNeeded) {
            throw new \RuntimeException('One or more FCM device deliveries need retry.');
        }
    }

    private function refreshNotificationOutcome(): void
    {
        if ($this->notificationId === null) return;
        $deliveries = NotificationPushDelivery::query()
            ->where('student_notification_id', $this->notificationId)
            ->get();
        if ($deliveries->isEmpty()) return;
        $accepted = $deliveries->where('status', NotificationPushDelivery::STATUS_ACCEPTED);
        $active = $deliveries->whereIn('status', [
            NotificationPushDelivery::STATUS_PENDING,
            NotificationPushDelivery::STATUS_DISPATCHING,
            NotificationPushDelivery::STATUS_RETRYABLE,
        ]);
        $unknown = $deliveries->contains(
            fn ($delivery): bool => $delivery->status === NotificationPushDelivery::STATUS_UNKNOWN
        );
        $providerFailure = $deliveries->contains(fn ($delivery): bool => in_array($delivery->status, [
            NotificationPushDelivery::STATUS_FAILED,
            NotificationPushDelivery::STATUS_UNKNOWN,
        ], true));
        $superseded = $deliveries->contains(
            fn ($delivery): bool => $delivery->status === NotificationPushDelivery::STATUS_SUPERSEDED
        );
        $terminalFailure = $providerFailure || $superseded;
        $attemptedAt = $deliveries->pluck('attempted_at')->filter()->sort()->first();
        $acceptedAt = $accepted->pluck('accepted_at')->filter()->sort()->first();
        $terminal = $active->isEmpty();

        StudentNotification::query()->whereKey($this->notificationId)->update([
            'push_attempted_at' => $attemptedAt,
            'push_attempts' => $deliveries->sum('attempts'),
            'push_sent_at' => $acceptedAt,
            'push_failed_at' => $terminal && $terminalFailure ? now() : null,
            'push_failure_code' => $terminalFailure
                ? ($unknown
                    ? 'delivery_unknown_after_worker_loss'
                    : ($accepted->isNotEmpty()
                        ? 'partial_delivery'
                        : ($providerFailure ? 'delivery_failed' : 'not_push_eligible')))
                : null,
            'updated_at' => now(),
        ]);
    }

    private function channelFor(string $type): string
    {
        if (NotificationDeliveryPolicy::isMarketing($type)) return 'rokn-offers';
        if (NotificationDeliveryPolicy::isLearningReminder($type)) return 'rokn-learning';
        return 'rokn-updates';
    }
}
