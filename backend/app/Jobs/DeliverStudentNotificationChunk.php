<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
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
        private ?string $link = null
    ) {
        $this->userIds = array_values(array_unique(array_map('intval', $this->userIds)));
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function handle(): void
    {
        $students = User::query()
            ->where('role', 'client')
            ->whereIn('id', $this->userIds)
            ->when(
                in_array($this->notificationType, ['course_promotion', 'admin_broadcast'], true),
                fn ($query) => $query->where('marketing_notifications_enabled', true)
            )
            ->get(['id']);

        foreach ($students as $student) {
            $identity = [
                'user_id' => $student->id,
                'delivery_key' => $this->deliveryKey,
            ];
            try {
                $notification = StudentNotification::query()->firstOrCreate($identity, [
                        'notification_type' => $this->notificationType,
                        'notifiable_type' => $this->notifiableType,
                        'notifiable_id' => $this->notifiableId,
                        'title_ar' => $this->titleAr,
                        'title_en' => $this->titleEn,
                        'message_ar' => $this->messageAr,
                        'message_en' => $this->messageEn,
                        'link' => $this->link,
                        'is_read' => false,
                        'read_at' => null,
                    ]);
            } catch (QueryException $exception) {
                // Laravel 9 firstOrCreate performs a read followed by insert.
                // The unique index wins a concurrent race; load that winner.
                $notification = StudentNotification::query()->where($identity)->first();
                if (!$notification) {
                    throw $exception;
                }
            }

            // Dispatching this on every chunk retry is intentional. The push
            // job atomically claims the notification row, so a retry repairs a
            // crash between inbox creation and queue dispatch without sending
            // the same push twice.
            SendUserPushNotification::dispatch((int) $notification->id)
                ->afterCommit();
        }
    }
}
