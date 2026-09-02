<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\AdminNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\Course;
use App\Models\FinancialEntitlementHold;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendStudentNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_ENROLLED = 'enrolled';
    public const AUDIENCE_NOT_ENROLLED = 'not_enrolled';
    public const MAX_EXPLICIT_USER_IDS = 500;

    protected string $notificationType;

    /** @var array<int> User IDs to notify (empty = all clients) */
    protected array $userIds;

    protected ?string $notifiableType;
    protected ?int $notifiableId;
    protected string $titleAr;
    protected string $titleEn;
    protected string $messageAr;
    protected string $messageEn;
    protected ?string $link;
    protected ?string $imageUrl = null;
    protected ?string $actionLabelAr = null;
    protected ?string $actionLabelEn = null;

    /** @var array<int> User IDs to exclude */
    protected array $excludeUserIds;

    protected string $deliveryKey = '';
    protected ?int $courseId = null;
    protected string $audience = self::AUDIENCE_ALL;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public array $backoff = [15, 60, 180];

    /**
     * @param string $notificationType
     * @param array<int> $userIds Explicit IDs for a small broadcast only
     * @param string|null $notifiableType
     * @param int|null $notifiableId
     * @param string $titleAr
     * @param string $titleEn
     * @param string $messageAr
     * @param string $messageEn
     * @param string|null $link
     * @param array<int> $excludeUserIds Explicit exclusions for a small broadcast only
     */
    public function __construct(
        string $notificationType,
        array $userIds,
        ?string $notifiableType,
        ?int $notifiableId,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        array $excludeUserIds = [],
        ?string $deliveryKey = null,
        ?int $courseId = null,
        string $audience = self::AUDIENCE_ALL,
        ?string $imageUrl = null,
        ?string $actionLabelAr = null,
        ?string $actionLabelEn = null
    ) {
        if (count($userIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification audience exceeds the safe broadcast limit.');
        }

        if (count($excludeUserIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification exclusions exceed the safe broadcast limit.');
        }

        $this->notificationType = $notificationType;
        $this->userIds          = $this->normalizeUserIds($userIds);
        $this->notifiableType   = $notifiableType;
        $this->notifiableId     = $notifiableId;
        $this->titleAr          = $titleAr;
        $this->titleEn          = $titleEn;
        $this->messageAr        = $messageAr;
        $this->messageEn        = $messageEn;
        $this->link             = $link;
        $this->excludeUserIds   = $this->normalizeUserIds($excludeUserIds);
        $this->deliveryKey      = $this->normalizeDeliveryKey($deliveryKey ?: (string) Str::uuid());
        $this->courseId         = $courseId;
        $this->audience         = $audience;
        $this->imageUrl         = $imageUrl;
        $this->actionLabelAr    = $actionLabelAr;
        $this->actionLabelEn    = $actionLabelEn;
        $this->validateAudienceSelector();
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        if ($this->deliveryKey === '') {
            $this->deliveryKey = $this->normalizeDeliveryKey((string) Str::uuid());
        }

        return $this->deliveryKey;
    }

    public function handle(): void
    {
        $this->uniqueId(); // Backfills jobs serialized by the previous release.

        try {
            if (!$this->courseStillDeliverable()) {
                $this->finishWithdrawnCourseCampaign();
                return;
            }
            if (Schema::hasTable('notification_campaigns')) {
                $persistedCampaign = NotificationCampaign::query()
                    ->where('delivery_key', $this->deliveryKey)
                    ->first(['id']);
                if ($persistedCampaign) {
                    $claimedCampaign = NotificationCampaign::query()
                        ->whereKey($persistedCampaign->id)
                        ->whereIn('status', [
                            NotificationCampaign::STATUS_QUEUED,
                            NotificationCampaign::STATUS_DELIVERING,
                        ])
                        ->update([
                            'status' => NotificationCampaign::STATUS_DELIVERING,
                            'failed_at' => null,
                            'failure_code' => null,
                        ]);
                    if ($claimedCampaign !== 1) {
                        // A delayed duplicate must never revive a completed,
                        // scheduled or manually dead-lettered campaign. Due
                        // schedules and explicit retries first move to queued.
                        return;
                    }
                }
            }
            $query = User::where('role', 'client')->where('active', true);

            // Promotions are opt-in both in the inbox and over push. Learning,
            // payment and account notifications remain unaffected.
            if (NotificationDeliveryPolicy::isMarketing($this->notificationType)) {
                $query->where('marketing_notifications_enabled', true);
            }

            $cooldownHours = $this->cooldownHours();
            if ($cooldownHours > 0) {
                $family = $this->cooldownFamily();
                $query->where(function ($eligible) use ($cooldownHours, $family): void {
                    $eligible->whereDoesntHave('studentNotifications', function ($notifications) use ($cooldownHours, $family): void {
                        $notifications
                            ->whereIn('notification_type', $family)
                            ->where('created_at', '>=', now()->subHours($cooldownHours));
                    })->orWhereHas('studentNotifications', function ($notifications): void {
                        $notifications->where('delivery_key', $this->deliveryKey);
                    });
                });
            }

            if (!empty($this->userIds)) {
                $query->whereIn('id', $this->userIds);
            }

            if (!empty($this->excludeUserIds)) {
                $query->whereNotIn('id', $this->excludeUserIds);
            }

            if ($this->courseId !== null && $this->audience === self::AUDIENCE_ENROLLED) {
                $query->whereHas('enrollments', function ($enrollments): void {
                    $enrollments
                        ->where('course_id', $this->courseId)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            } elseif ($this->courseId !== null && $this->audience === self::AUDIENCE_NOT_ENROLLED) {
                $query->whereDoesntHave('enrollments', function ($enrollments): void {
                    $enrollments
                        ->where('course_id', $this->courseId)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            }
            if ($this->courseId !== null && Schema::hasTable('financial_entitlement_holds')) {
                $query->whereDoesntHave('enrollments.financialHolds', function ($holds): void {
                    $holds->where('course_id', $this->courseId)
                        ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                        ->whereIn('entitlement_scope', ['course', 'plan', 'chat']);
                });
            }

            $campaign = Schema::hasTable('notification_campaigns')
                ? NotificationCampaign::query()->where('delivery_key', $this->deliveryKey)->first()
                : null;
            $durableAudience = $campaign && Schema::hasTable('notification_campaign_recipients');
            $queuedRecipientsCount = 0;

            if ($durableAudience) {
                // Snapshot selection progress itself. A coordinator crash resumes
                // after its last committed user ID instead of selecting a changed
                // audience from the beginning or losing already selected chunks.
                if (!$campaign->selection_finished_at) {
                    $query->where('id', '>', (int) $campaign->selection_cursor);
                    $query->select('id')->orderBy('id')->chunkById(500, function ($students) use ($campaign): void {
                        $now = now();
                        $rows = $students->map(fn ($student): array => [
                            'notification_campaign_id' => $campaign->id,
                            'user_id' => (int) $student->id,
                            'status' => NotificationCampaignRecipient::STATUS_PENDING,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all();
                        if ($rows !== []) {
                            NotificationCampaignRecipient::query()->insertOrIgnore($rows);
                            NotificationCampaign::query()->whereKey($campaign->id)->update([
                                'selection_cursor' => (int) $students->max('id'),
                                'updated_at' => now(),
                            ]);
                        }
                        $this->dispatchChunk($students->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    }, 'id');

                    $queuedRecipientsCount = NotificationCampaignRecipient::query()
                        ->where('notification_campaign_id', $campaign->id)
                        ->count();
                    NotificationCampaign::query()->whereKey($campaign->id)->update([
                        'recipients_count' => $queuedRecipientsCount,
                        'selection_finished_at' => now(),
                        'coordinator_finished_at' => now(),
                        'status' => $queuedRecipientsCount > 0
                            ? NotificationCampaign::STATUS_DELIVERING
                            : NotificationCampaign::STATUS_COMPLETED,
                        'completed_at' => $queuedRecipientsCount > 0 ? null : now(),
                        'failed_at' => null,
                        'failure_code' => null,
                        'updated_at' => now(),
                    ]);
                    $this->dispatchPendingRecipients($campaign);
                    $this->completeResolvedCampaign($campaign, $queuedRecipientsCount);
                } else {
                    // Recovery only requeues unresolved members of the original
                    // snapshot. New users are not silently added to an old campaign.
                    NotificationCampaignRecipient::query()
                        ->where('notification_campaign_id', $campaign->id)
                        ->where('status', NotificationCampaignRecipient::STATUS_DELIVERING)
                        ->where('claimed_at', '<=', now()->subMinutes(15))
                        ->update([
                            'status' => NotificationCampaignRecipient::STATUS_PENDING,
                            'claimed_at' => null,
                            'resolution_code' => 'coordinator_recovery',
                            'updated_at' => now(),
                        ]);
                    $this->dispatchPendingRecipients($campaign);
                    $queuedRecipientsCount = (int) $campaign->recipients_count;
                    NotificationCampaign::query()->whereKey($campaign->id)->update([
                        'coordinator_finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->completeResolvedCampaign($campaign, $queuedRecipientsCount);
                }
            } else {
                // Rolling-deploy fallback until the resumable-recipient table is present.
                $query->select('id')->orderBy('id')->chunkById(500, function ($students) use (&$queuedRecipientsCount): void {
                    $queuedRecipientsCount += $students->count();
                    $this->dispatchChunk($students->pluck('id')->map(fn ($id): int => (int) $id)->all());
                }, 'id');
            }

            $updated = 0;
            if (Schema::hasTable('notification_campaigns') && !$durableAudience) {
                $updated = NotificationCampaign::query()
                    ->where('delivery_key', $this->deliveryKey)
                    ->update([
                    'status' => $queuedRecipientsCount > 0
                        ? NotificationCampaign::STATUS_DELIVERING
                        : NotificationCampaign::STATUS_COMPLETED,
                    'recipients_count' => $queuedRecipientsCount,
                    'coordinator_finished_at' => now(),
                    'completed_at' => $queuedRecipientsCount > 0 ? null : now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    ]);
            }
            if ($updated && $queuedRecipientsCount > 0) {
                NotificationCampaign::query()
                    ->where('delivery_key', $this->deliveryKey)
                    ->whereColumn('inbox_count', '>=', 'recipients_count')
                    ->update([
                        'status' => NotificationCampaign::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
            }

            Log::info('Student notification chunks queued', [
                'notification_type' => $this->notificationType,
                'explicit_user_ids_count' => count($this->userIds),
                'excluded_user_ids_count' => count($this->excludeUserIds),
                'queued_recipients_count' => $queuedRecipientsCount,
                'audience'          => $this->audience,
                'course_id'         => $this->courseId,
                'notifiable_type'   => $this->notifiableType,
                'notifiable_id'     => $this->notifiableId,
                'delivery_key'      => $this->deliveryKey,
                'title_ar'          => $this->titleAr,
                'title_en'          => $this->titleEn,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send student notifications', [
                'notification_type' => $this->notificationType,
                'explicit_user_ids_count' => count($this->userIds),
                'excluded_user_ids_count' => count($this->excludeUserIds),
                'audience'          => $this->audience,
                'course_id'         => $this->courseId,
                'exception'         => $e::class,
                'error_fingerprint' => hash('sha256', $e->getMessage()),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if (Schema::hasTable('notification_campaigns')) {
            NotificationCampaign::query()
                ->where('delivery_key', $this->deliveryKey)
                ->whereIn('status', [
                    NotificationCampaign::STATUS_QUEUED,
                    NotificationCampaign::STATUS_DELIVERING,
                ])
                ->update([
                    'status' => NotificationCampaign::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'coordinator_' . substr(hash('sha256', $exception::class), 0, 12),
                ]);
        }
        Log::error('SendStudentNotification job failed', [
            'notification_type' => $this->notificationType,
            'explicit_user_ids_count' => count($this->userIds),
            'excluded_user_ids_count' => count($this->excludeUserIds),
            'audience'          => $this->audience,
            'course_id'         => $this->courseId,
            'delivery_key'      => $this->deliveryKey,
            'exception'         => $exception::class,
            'error_fingerprint' => hash('sha256', $exception->getMessage()),
        ]);
    }

    private function normalizeDeliveryKey(string $deliveryKey): string
    {
        $deliveryKey = trim($deliveryKey);

        return strlen($deliveryKey) <= 64 ? $deliveryKey : hash('sha256', $deliveryKey);
    }

    private function courseStillDeliverable(): bool
    {
        if ($this->courseId === null) return true;
        $course = Course::query()->find($this->courseId);
        if (!$course || !$course->isPublishedForLearning()) return false;

        return $this->audience === self::AUDIENCE_ENROLLED
            || (bool) $course->is_catalog_visible;
    }

    private function finishWithdrawnCourseCampaign(): void
    {
        if (!Schema::hasTable('notification_campaigns')) return;
        NotificationCampaign::query()
            ->where('delivery_key', $this->deliveryKey)
            ->whereIn('status', [
                NotificationCampaign::STATUS_QUEUED,
                NotificationCampaign::STATUS_SCHEDULED,
                NotificationCampaign::STATUS_DELIVERING,
            ])
            ->update([
                'status' => NotificationCampaign::STATUS_COMPLETED,
                'recipients_count' => 0,
                'resolved_count' => 0,
                'completed_at' => now(),
                'coordinator_finished_at' => now(),
                'failure_code' => 'course_withdrawn_before_delivery',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<int, mixed> $userIds
     * @return array<int>
     */
    private function normalizeUserIds(array $userIds): array
    {
        $normalized = array_map('intval', $userIds);

        return array_values(array_filter(array_unique($normalized), static fn (int $id): bool => $id > 0));
    }

    private function validateAudienceSelector(): void
    {
        $allowedAudiences = [
            self::AUDIENCE_ALL,
            self::AUDIENCE_ENROLLED,
            self::AUDIENCE_NOT_ENROLLED,
        ];

        if (!in_array($this->audience, $allowedAudiences, true)) {
            throw new \InvalidArgumentException('Unsupported notification audience selector.');
        }

        if ($this->courseId !== null && $this->courseId <= 0) {
            throw new \InvalidArgumentException('Course selector must contain a positive course ID.');
        }

        if ($this->audience !== self::AUDIENCE_ALL && $this->courseId === null) {
            throw new \InvalidArgumentException('Course ID is required for a course notification audience.');
        }

        if (count($this->userIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification audience exceeds the safe broadcast limit.');
        }

        if (count($this->excludeUserIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification exclusions exceed the safe broadcast limit.');
        }
    }

    private function cooldownHours(): int
    {
        if (
            !Schema::hasTable('admin_notifications')
            || !Schema::hasColumn('admin_notifications', 'system_key')
        ) {
            return NotificationDeliveryPolicy::defaultCooldownHours($this->notificationType);
        }

        $configured = AdminNotification::query()
            ->where('system_key', $this->notificationType)
            ->value('cooldown_hours');

        return $configured === null
            ? NotificationDeliveryPolicy::defaultCooldownHours($this->notificationType)
            : max(0, (int) $configured);
    }

    /** @param array<int> $userIds */
    private function dispatchChunk(array $userIds): void
    {
        if ($userIds === []) return;
        DeliverStudentNotificationChunk::dispatch(
            $userIds,
            $this->deliveryKey,
            $this->notificationType,
            $this->notifiableType,
            $this->notifiableId,
            $this->titleAr,
            $this->titleEn,
            $this->messageAr,
            $this->messageEn,
            $this->link,
            $this->imageUrl,
            $this->actionLabelAr,
            $this->actionLabelEn
        )->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    private function dispatchPendingRecipients(NotificationCampaign $campaign): void
    {
        NotificationCampaignRecipient::query()
            ->where('notification_campaign_id', $campaign->id)
            ->where('status', NotificationCampaignRecipient::STATUS_PENDING)
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($recipients): void {
                $this->dispatchChunk($recipients->pluck('user_id')->map(
                    fn ($id): int => (int) $id
                )->all());
            });
    }

    private function completeResolvedCampaign(NotificationCampaign $campaign, int $recipientCount): void
    {
        DB::transaction(function () use ($campaign, $recipientCount): void {
            $freshCampaign = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();
            if (!$freshCampaign) return;
            $inbox = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->where('status', NotificationCampaignRecipient::STATUS_INBOX)
                ->count();
            $skipped = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->where('status', NotificationCampaignRecipient::STATUS_SKIPPED)
                ->count();
            $resolved = $inbox + $skipped;
            $complete = $resolved >= $recipientCount;
            $freshCampaign->forceFill([
                'inbox_count' => $inbox,
                'skipped_count' => $skipped,
                'resolved_count' => $resolved,
                'status' => $complete
                    ? NotificationCampaign::STATUS_COMPLETED
                    : NotificationCampaign::STATUS_DELIVERING,
                'completed_at' => $complete ? now() : null,
                'failed_at' => null,
                'failure_code' => $complete ? null : $freshCampaign->failure_code,
            ])->save();
        }, 3);
    }

    /** @return array<int,string> */
    private function cooldownFamily(): array
    {
        $knownFamily = match ($this->notificationType) {
            'new_course_lesson', 'new_quiz', 'course_update' => [
                'new_course_lesson',
                'new_quiz',
                'course_update',
            ],
            'learning_nudge', 'continue_course' => [
                'learning_nudge',
                'continue_course',
            ],
            default => null,
        };

        return $knownFamily ?: NotificationDeliveryPolicy::frequencyFamily($this->notificationType);
    }
}
