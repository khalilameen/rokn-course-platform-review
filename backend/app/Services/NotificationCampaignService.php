<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NotificationCampaignService
{
    /** @param array<int> $userIds @param array<int> $excludeUserIds */
    public function queue(
        string $notificationType,
        array $userIds,
        ?string $notifiableType,
        ?int $notifiableId,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link,
        array $excludeUserIds,
        string $deliveryKey,
        ?int $courseId,
        string $audience,
        ?string $imageUrl = null,
        ?string $actionLabelAr = null,
        ?string $actionLabelEn = null,
        ?\DateTimeInterface $scheduledAt = null
    ): bool {
        $userIds = $this->normalizeUserIds($userIds);
        $excludeUserIds = $this->normalizeUserIds($excludeUserIds);
        $deliveryKey = trim($deliveryKey);
        if ($deliveryKey === '') {
            $deliveryKey = (string) Str::uuid();
        } elseif (strlen($deliveryKey) > 64) {
            $deliveryKey = hash('sha256', $deliveryKey);
        }

        $job = new SendStudentNotification(
            $notificationType,
            $userIds,
            $notifiableType,
            $notifiableId,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            $excludeUserIds,
            $deliveryKey,
            $courseId,
            $audience,
            $imageUrl,
            $actionLabelAr,
            $actionLabelEn
        );

        if (!Schema::hasTable('notification_campaigns')) {
            DurableJobDispatch::now($job);
            return true;
        }

        $requestedAt = $scheduledAt
            ? \Illuminate\Support\Carbon::instance($scheduledAt)->utc()
            : now();
        $allowedAt = NotificationDeliveryPolicy::nextAllowedAt($notificationType, $requestedAt);
        $scheduledAt = $allowedAt->isAfter(now()->addSeconds(30)) ? $allowedAt : null;
        $supportsScheduling = Schema::hasColumn('notification_campaigns', 'scheduled_at');
        $isScheduled = $supportsScheduling
            && $scheduledAt
            && $scheduledAt->isAfter(now()->addSeconds(30));
        $campaignValues = [
            'notification_type' => $notificationType,
            'audience' => $audience,
            'course_id' => $courseId,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'user_ids' => array_values($userIds),
            'exclude_user_ids' => array_values($excludeUserIds),
            'authored_by' => auth()->id(),
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'action_label_ar' => $actionLabelAr,
            'action_label_en' => $actionLabelEn,
            'link' => $link,
            'image_url' => $imageUrl,
            'status' => $isScheduled
                ? NotificationCampaign::STATUS_SCHEDULED
                : NotificationCampaign::STATUS_QUEUED,
            'queued_at' => $isScheduled ? null : now(),
        ];
        if ($supportsScheduling) {
            $campaignValues['scheduled_at'] = $isScheduled ? $scheduledAt : null;
        }
        $campaign = NotificationCampaign::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            $campaignValues
        );

        if (!$campaign->wasRecentlyCreated) {
            if (!$this->sameImmutablePayload($campaign, $campaignValues)) {
                throw new \DomainException('notification_delivery_key_payload_mismatch');
            }
            return false;
        }

        if ($isScheduled) {
            return true;
        }

        // A queue connection can fail when the commit callback actually runs,
        // after the campaign and its image reference are already durable. Do
        // not turn that committed campaign into a false failed form submit (or
        // let the controller delete its image). Persist a retryable dead letter
        // while keeping the dashboard request successful and truthful.
        DB::afterCommit(static function () use ($job, $campaign): void {
            try {
                DurableJobDispatch::now($job);
            } catch (\Throwable $exception) {
                NotificationCampaign::query()
                    ->whereKey($campaign->getKey())
                    ->where('status', NotificationCampaign::STATUS_QUEUED)
                    ->update([
                        'status' => NotificationCampaign::STATUS_FAILED,
                        'failed_at' => now(),
                        'failure_code' => 'queue_' . substr(hash('sha256', $exception::class), 0, 12),
                        'updated_at' => now(),
                    ]);
                report($exception);
            }
        });
        return true;
    }

    /**
     * Manually recover a campaign after automatic delivery recovery is exhausted.
     * Existing inbox rows keep the same delivery key, so the retry fills only the
     * missing recipients instead of creating a second notification.
     */
    public function retry(NotificationCampaign $campaign): bool
    {
        $claimed = NotificationCampaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', NotificationCampaign::STATUS_FAILED)
            ->update([
                'status' => NotificationCampaign::STATUS_QUEUED,
                'retry_count' => 0,
                'queued_at' => now(),
                'coordinator_finished_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $job = $this->jobForCampaign($campaign);

        try {
            DurableJobDispatch::afterCommit($job);
        } catch (\Throwable $exception) {
            NotificationCampaign::query()
                ->whereKey($campaign->getKey())
                ->where('status', NotificationCampaign::STATUS_QUEUED)
                ->update([
                    'status' => NotificationCampaign::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'queue_' . substr(hash('sha256', $exception::class), 0, 12),
                    'updated_at' => now(),
                ]);
            throw $exception;
        }

        return true;
    }

    public function jobForCampaign(NotificationCampaign $campaign): SendStudentNotification
    {
        return new SendStudentNotification(
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
        );
    }

    /** @param array<int,mixed> $ids @return array<int> */
    private function normalizeUserIds(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)),
            static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param array<string,mixed> $expected */
    private function sameImmutablePayload(NotificationCampaign $campaign, array $expected): bool
    {
        foreach ([
            'notification_type', 'audience', 'notifiable_type', 'title_ar', 'title_en',
            'message_ar', 'message_en', 'action_label_ar', 'action_label_en', 'link', 'image_url',
        ] as $field) {
            if ((string) ($campaign->{$field} ?? '') !== (string) ($expected[$field] ?? '')) {
                return false;
            }
        }
        foreach (['course_id', 'notifiable_id'] as $field) {
            if ((int) ($campaign->{$field} ?? 0) !== (int) ($expected[$field] ?? 0)) {
                return false;
            }
        }

        return $this->normalizeUserIds((array) ($campaign->user_ids ?? []))
                === $this->normalizeUserIds((array) ($expected['user_ids'] ?? []))
            && $this->normalizeUserIds((array) ($campaign->exclude_user_ids ?? []))
                === $this->normalizeUserIds((array) ($expected['exclude_user_ids'] ?? []));
    }
}
