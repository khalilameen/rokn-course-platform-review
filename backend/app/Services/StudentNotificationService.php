<?php

namespace App\Services;

use App\Jobs\SendUserPushNotification;
use App\Models\CoinEarningMethod;
use App\Models\StudentNotification;
use App\Models\Setting;
use App\Models\RewardRule;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class StudentNotificationService
{
    public const TYPE_COURSE_ENROLLED = 'course_enrolled';
    public const TYPE_COINS_CLAIMED = 'coins_claimed';
    public const TYPE_PACKAGE_PURCHASED = 'package_purchased';
    public const TYPE_COURSE_COMPLETED = 'course_completed';
    public const TYPE_CERTIFICATE_READY = 'certificate_ready';
    public const TYPE_INSTITUTIONAL_GRANT = 'institutional_grant';
    public const TYPE_WHATSAPP_CONNECTED = 'whatsapp_connected';
    public const TYPE_SUPPORT_CASE_UPDATE = 'support_case_update';

    /**
     * Create a StudentNotification for the user and send FCM push.
     *
     * @param User $user
     * @param string $type
     * @param string $titleAr
     * @param string $titleEn
     * @param string $messageAr
     * @param string $messageEn
     * @param string|null $link
     * @param string|null $notifiableType
     * @param int|null $notifiableId
     * @return StudentNotification|null Null when the dashboard disabled this automated template.
     */
    public static function notifyUser(
        User $user,
        string $type,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        ?string $notifiableType = null,
        ?int $notifiableId = null,
        ?string $deliveryKey = null,
        array $templateVariables = [],
        ?string $imageUrl = null
    ): ?StudentNotification {
        $copy = app(EngagementMessageService::class)->notificationPayload(
            $type,
            $templateVariables,
            [
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'message_ar' => $messageAr,
                'message_en' => $messageEn,
                'action_label_ar' => null,
                'action_label_en' => null,
                'image_url' => $imageUrl,
            ]
        );
        if ($copy === null) {
            return null;
        }
        $deliveryKey = self::normalizeDeliveryKey($deliveryKey ?: (string) Str::uuid());
        $identity = [
            'user_id' => $user->id,
            'delivery_key' => $deliveryKey,
        ];
        $notification = DB::transaction(function () use (
            $user,
            $identity,
            $type,
            $notifiableType,
            $notifiableId,
            $copy,
            $link,
            $imageUrl
        ): ?StudentNotification {
            // User is the aggregate lock. Account deletion takes the same lock
            // before clearing inbox rows, so a stale callback cannot recreate
            // personal notifications after the account has gone away.
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser || !NotificationDeliveryPolicy::allowsInbox($lockedUser, $type)) {
                return null;
            }

            try {
                return StudentNotification::query()->firstOrCreate($identity, [
                    'notification_type' => $type,
                    'notifiable_type' => $notifiableType,
                    'notifiable_id' => $notifiableId,
                    'title_ar' => $copy['title_ar'],
                    'title_en' => $copy['title_en'],
                    'message_ar' => $copy['message_ar'],
                    'message_en' => $copy['message_en'],
                    'link' => $link ?: ($copy['template_link'] ?? null),
                    'image_url' => $imageUrl ?: ($copy['image_url'] ?? null),
                    'action_label_ar' => $copy['action_label_ar'] ?? null,
                    'action_label_en' => $copy['action_label_en'] ?? null,
                    'is_read' => false,
                ]);
            } catch (QueryException $exception) {
                $existing = StudentNotification::query()->where($identity)->first();
                if (!$existing) {
                    throw $exception;
                }

                return $existing;
            }
        });

        if (!$notification) {
            return null;
        }

        // Persist first so the in-app inbox is authoritative. Push delivery is
        // an after-commit side effect and can scale independently on workers.
        self::enqueuePushAfterCommit((int) $notification->id);

        return $notification;
    }

    /**
     * Grant registration bonus coins and send localized FCM push notification.
     *
     * @param User $user
     * @return int Number of coins credited during this call.
     */
    public static function sendRegistrationBonus(User $user): int
    {
        try {
            if (app(AcquisitionRewardTombstoneService::class)->userHasConsumed(
                $user,
                AcquisitionRewardTombstoneService::WELCOME_REWARD
            )) {
                return 0;
            }

            $method = CoinEarningMethod::active()->where('action_key', 'register')->first();

            // Keep the granted amount identical to the login promise. The
            // earning-method row remains the claim/audit record, not a second
            // source of truth for this acquisition offer.
            $coinsAmount = RewardRule::configuredAmount(
                'welcome_bonus',
                (int) (Setting::query()->value('welcome_bonus_coins')
                    ?? config('social_auth.welcome_bonus_coins', 20))
            );
            $settings = Setting::query()->first();
            if (
                $settings
                && $settings->recommended_social_provider
                && $user->social_provider === $settings->recommended_social_provider
            ) {
                $coinsAmount += max(0, (int) $settings->recommended_provider_bonus_coins);
            }
            $methodId = $method ? $method->id : null;

            if ($coinsAmount <= 0) {
                return 0;
            }

            $idempotencyKey = 'registration-bonus:' . $user->id;

            return DB::transaction(function () use (
                $user,
                $method,
                $methodId,
                $coinsAmount,
                $idempotencyKey
            ): int {
                // Serialize first-login retries. Wallet credit, audit row and
                // inbox notification either complete together or can be retried.
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                $alreadyCredited = WalletTransaction::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();
                $alreadyClaimed = $methodId
                    ? $lockedUser->coinEarnings()
                        ->where('coin_earning_method_id', $methodId)
                        ->exists()
                    : false;

                // Preserve bonuses issued by the legacy system even when they
                // predate the wallet ledger. Never add a second welcome credit.
                if ($alreadyClaimed && !$alreadyCredited) {
                    return 0;
                }

                if (!$alreadyCredited) {
                    app(WalletService::class)->credit(
                        $lockedUser->id,
                        $coinsAmount,
                        'welcome_bonus',
                        $idempotencyKey,
                        $method,
                        ['action_key' => 'register'],
                        WalletTransaction::BUCKET_REWARD
                    );
                }

                if ($methodId) {
                    $lockedUser->coinEarnings()->firstOrCreate(
                        ['coin_earning_method_id' => $methodId],
                        ['amount' => $coinsAmount]
                    );
                }

                self::ensureRegistrationBonusNotification(
                    $lockedUser,
                    $coinsAmount,
                    $methodId
                );

                return $alreadyCredited ? 0 : $coinsAmount;
            }, 3);
        } catch (\Throwable $e) {
            if (app()->environment('testing')) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('Failed to grant registration bonus', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
            return 0;
        }
    }

    private static function ensureRegistrationBonusNotification(
        User $user,
        int $coinsAmount,
        ?int $methodId
    ): void
    {
        $copy = app(EngagementMessageService::class)->notificationPayload(
            'welcome_bonus_received',
            ['coins' => $coinsAmount],
            [
                'title_ar' => 'وصلت هديتك',
                'title_en' => 'Your balance is ready',
                'message_ar' => self::arabicDigits($coinsAmount)
                    . ' عملة ركن في محفظتك',
                'message_en' => $coinsAmount . ' Rokn coins are in your wallet',
                'action_label_ar' => 'افتح المحفظة',
                'action_label_en' => 'View balance',
            ]
        );
        if ($copy === null) {
            return;
        }

        // The delivery key is the stable identity for this one-time receipt.
        // The surrounding user lock prevents concurrent first-login duplicates.
        $notification = StudentNotification::firstOrCreate(
            [
                'user_id' => $user->id,
                'delivery_key' => self::normalizeDeliveryKey('registration-bonus:' . $user->id),
            ],
            [
                'notification_type' => self::TYPE_COINS_CLAIMED,
                'notifiable_type' => $methodId ? CoinEarningMethod::class : null,
                'notifiable_id' => $methodId,
                'title_ar' => $copy['title_ar'],
                'title_en' => $copy['title_en'],
                'message_ar' => $copy['message_ar'],
                'message_en' => $copy['message_en'],
                'link' => $copy['template_link'] ?? null,
                'image_url' => $copy['image_url'] ?? null,
                'action_label_ar' => $copy['action_label_ar'] ?? null,
                'action_label_en' => $copy['action_label_en'] ?? null,
                'is_read' => false,
            ]
        );

        if (!$notification->wasRecentlyCreated) {
            return;
        }

        self::enqueuePushAfterCommit((int) $notification->id);
    }

    private static function enqueuePushAfterCommit(int $notificationId): void
    {
        // Catch inside the commit callback, not only around its registration.
        // Queue connections fail when the callback actually runs; allowing that
        // exception out can make a completed purchase or reward look failed.
        DB::afterCommit(static function () use ($notificationId): void {
            try {
                SendUserPushNotification::dispatch($notificationId);
            } catch (\Throwable $exception) {
                // The inbox row is durable and RetryStalledNotificationPushes
                // will enqueue it after the queue connection returns.
                report($exception);
            }
        });
    }

    private static function arabicDigits(int $value): string
    {
        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }

    private static function normalizeDeliveryKey(string $deliveryKey): string
    {
        $deliveryKey = trim($deliveryKey);

        return strlen($deliveryKey) <= 64 ? $deliveryKey : hash('sha256', $deliveryKey);
    }
}
