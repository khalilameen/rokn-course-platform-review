<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
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
        ?string $actionLabelEn = null
    ): bool {
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
            dispatch($job);
            return true;
        }

        $campaign = NotificationCampaign::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            [
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
                'status' => NotificationCampaign::STATUS_QUEUED,
                'queued_at' => now(),
            ]
        );

        if (!$campaign->wasRecentlyCreated) {
            return false;
        }

        try {
            dispatch($job)->afterCommit();
        } catch (\Throwable $exception) {
            $campaign->forceFill([
                'status' => NotificationCampaign::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => 'queue_' . substr(hash('sha256', $exception::class), 0, 12),
            ])->save();
            throw $exception;
        }
        return true;
    }
}
