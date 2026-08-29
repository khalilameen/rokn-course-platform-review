<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

    /** @var array<int> User IDs to exclude */
    protected array $excludeUserIds;

    protected string $deliveryKey = '';
    protected ?int $courseId = null;
    protected string $audience = self::AUDIENCE_ALL;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 3600;
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
        string $audience = self::AUDIENCE_ALL
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
            $query = User::where('role', 'client');

            // Promotions are opt-in both in the inbox and over push. Learning,
            // payment and account notifications remain unaffected.
            if (in_array($this->notificationType, ['course_promotion', 'admin_broadcast'], true)) {
                $query->where('marketing_notifications_enabled', true);
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
                        ->where('is_active', true);
                });
            } elseif ($this->courseId !== null && $this->audience === self::AUDIENCE_NOT_ENROLLED) {
                $query->whereDoesntHave('enrollments', function ($enrollments): void {
                    $enrollments
                        ->where('course_id', $this->courseId)
                        ->where('is_active', true);
                });
            }

            // The coordinator never holds the whole audience in memory. Each
            // bounded job can be retried independently; the database delivery
            // key remains the final authority against duplicate inbox rows.
            $queuedRecipientsCount = 0;
            $query->select('id')->orderBy('id')->chunkById(500, function ($students) use (&$queuedRecipientsCount): void {
                $queuedRecipientsCount += $students->count();
                DeliverStudentNotificationChunk::dispatch(
                    $students->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $this->deliveryKey,
                    $this->notificationType,
                    $this->notifiableType,
                    $this->notifiableId,
                    $this->titleAr,
                    $this->titleEn,
                    $this->messageAr,
                    $this->messageEn,
                    $this->link
                )->onQueue((string) config('queue.channels.notifications', 'notifications'));
            }, 'id');

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
}
