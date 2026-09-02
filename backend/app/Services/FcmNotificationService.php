<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use App\Support\ArabicDisplayText;
use App\Support\RoknLocale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;

class FcmNotificationService
{
    public const CIRCUIT_KEY = 'fcm:circuit-open';
    private const FAILURE_KEY = 'fcm:circuit-failures';

    /**
     * Send FCM push notification to all devices of a user via FCM HTTP v1 API.
     *
     * @param User $user
     * @param string $titleAr
     * @param string $titleEn
     * @param string $messageAr
     * @param string $messageEn
     * @param string|null $link
     * @return bool True only when at least one device accepted the push
     */
    public static function sendToUser(
        User $user,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        array $extraData = []
    ): bool {
        return self::sendToUserDetailed(
            $user,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            $extraData
        )['accepted'];
    }

    /** @return array{accepted:bool,retryable:bool} */
    public static function sendToUserDetailed(
        User $user,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        array $extraData = []
    ): array {
        $titleAr = self::firstText($titleAr, $titleEn, 'إشعار من ركن');
        $titleEn = self::firstText($titleEn, $titleAr, 'Rokn notification');
        $messageAr = self::firstText($messageAr, $messageEn);
        $messageEn = self::firstText($messageEn, $messageAr);

        // A user can still see their in-app inbox after opting out, but we must
        // never wake their device with a push notification once they turn it off.
        $notificationType = (string) ($extraData['notification_type'] ?? '');
        if (!NotificationDeliveryPolicy::allowsPush($user, $notificationType)) {
            return ['accepted' => false, 'retryable' => false];
        }

        $tokens = $user->relationLoaded('deviceTokens')
            ? $user->deviceTokens
            : UserDeviceToken::where('user_id', $user->id)->get();

        if ($tokens->isEmpty()) {
            return ['accepted' => false, 'retryable' => false];
        }
        if (self::circuitIsOpen()) {
            return ['accepted' => false, 'retryable' => true];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (\Throwable $e) {
            self::recordFailure('binding');
            Log::warning('FCM messaging service unavailable', [
                'exception' => $e::class,
            ]);
            return ['accepted' => false, 'retryable' => true];
        }

        $attempted = false;
        $retryableFailure = false;
        $staleTokenIds = [];

        foreach ($tokens as $tokenRecord) {
            $deviceToken = $tokenRecord->device_token;
            if (empty($deviceToken)) {
                continue;
            }

            $isEnglish = RoknLocale::normalize($user->preferred_locale) === RoknLocale::ENGLISH;
            $title = $isEnglish ? $titleEn : ArabicDisplayText::format($titleAr);
            $body = $isEnglish ? $messageEn : ArabicDisplayText::format($messageAr);

            $data = [
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'message_ar' => $messageAr,
                'message_en' => $messageEn,
            ];
            if ($link !== null) {
                $data['link'] = $link;
            }
            foreach ($extraData as $key => $value) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                    continue;
                }
                if (is_scalar($value) && $value !== '') {
                    $data[$key] = (string) $value;
                }
            }

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            try {
                $messaging->send($message);
                $attempted = true;
                self::recordSuccess();
            } catch (NotFound $e) {
                $staleTokenIds[] = $tokenRecord->id;
            } catch (MessagingException $e) {
                $retryableFailure = true;
                self::recordFailure('messaging');
                Log::warning('FCM send failed for token', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable $e) {
                $retryableFailure = true;
                self::recordFailure('unexpected');
                Log::error('Unexpected FCM error', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                ]);
            }
        }

        if (!empty($staleTokenIds)) {
            UserDeviceToken::whereIn('id', $staleTokenIds)->delete();
        }

        return [
            'accepted' => $attempted,
            'retryable' => !$attempted && $retryableFailure,
        ];
    }

    /**
     * One durable delivery row calls this method for one native token. Keeping
     * the provider result per device prevents one healthy phone from hiding a
     * failed second phone belonging to the same learner.
     *
     * @return array{accepted:bool,retryable:bool,failure_code:?string}
     */
    public static function sendToDeviceDetailed(
        User $user,
        UserDeviceToken $tokenRecord,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        array $extraData = []
    ): array {
        $type = (string) ($extraData['notification_type'] ?? '');
        if (!NotificationDeliveryPolicy::allowsPush($user, $type)) {
            return ['accepted' => false, 'retryable' => false, 'failure_code' => 'preference_disabled'];
        }
        $deviceToken = trim((string) $tokenRecord->device_token);
        if ($deviceToken === '') {
            return ['accepted' => false, 'retryable' => false, 'failure_code' => 'token_missing'];
        }
        if (self::circuitIsOpen()) {
            return ['accepted' => false, 'retryable' => true, 'failure_code' => 'provider_circuit_open'];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (\Throwable $exception) {
            self::recordFailure('binding');
            Log::warning('FCM messaging service unavailable', ['exception' => $exception::class]);
            return ['accepted' => false, 'retryable' => true, 'failure_code' => 'provider_unavailable'];
        }

        $titleAr = self::firstText($titleAr, $titleEn, 'إشعار من ركن');
        $titleEn = self::firstText($titleEn, $titleAr, 'Rokn notification');
        $messageAr = self::firstText($messageAr, $messageEn);
        $messageEn = self::firstText($messageEn, $messageAr);
        $isEnglish = RoknLocale::normalize($user->preferred_locale) === RoknLocale::ENGLISH;
        $title = $isEnglish ? $titleEn : ArabicDisplayText::format($titleAr);
        $body = $isEnglish ? $messageEn : ArabicDisplayText::format($messageAr);
        $data = [
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
        ];
        if ($link !== null) $data['link'] = $link;
        foreach ($extraData as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) continue;
            if (is_scalar($value) && $value !== '') $data[$key] = (string) $value;
        }

        $image = trim((string) ($extraData['image_url'] ?? ''));
        if (!filter_var($image, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($image), 'https://')) {
            $image = '';
        }
        $channel = trim((string) ($extraData['channel_id'] ?? 'rokn-updates'));
        if (!in_array($channel, ['rokn-learning', 'rokn-offers', 'rokn-updates'], true)) {
            $channel = 'rokn-updates';
        }
        $message = CloudMessage::withTarget('token', $deviceToken)
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withAndroidConfig([
                'priority' => 'normal',
                'notification' => array_filter([
                    'channel_id' => $channel,
                    'image' => $image ?: null,
                ]),
            ]);
        if ($image !== '') {
            $message = $message->withApnsConfig([
                'payload' => ['aps' => ['mutable-content' => 1]],
                'fcm_options' => ['image' => $image],
            ]);
        }
        try {
            $messaging->send($message);
            self::recordSuccess();
            return ['accepted' => true, 'retryable' => false, 'failure_code' => null];
        } catch (NotFound $exception) {
            return ['accepted' => false, 'retryable' => false, 'failure_code' => 'token_unregistered'];
        } catch (InvalidArgument|InvalidMessage $exception) {
            Log::error('FCM rejected a notification payload.', [
                'user_id' => $user->id,
                'token_id' => $tokenRecord->id,
                'exception' => $exception::class,
            ]);
            return ['accepted' => false, 'retryable' => false, 'failure_code' => 'payload_invalid'];
        } catch (AuthenticationError $exception) {
            self::recordFailure('authentication');
            Log::error('FCM credentials rejected.', ['exception' => $exception::class]);
            return ['accepted' => false, 'retryable' => false, 'failure_code' => 'provider_authentication'];
        } catch (ApiConnectionFailed|QuotaExceeded|ServerError|ServerUnavailable $exception) {
            self::recordFailure('provider_temporary');
            Log::warning('FCM temporarily unavailable for token.', [
                'user_id' => $user->id,
                'token_id' => $tokenRecord->id,
                'exception' => $exception::class,
            ]);
            return ['accepted' => false, 'retryable' => true, 'failure_code' => 'provider_unavailable'];
        } catch (MessagingException $exception) {
            self::recordFailure('messaging');
            Log::warning('FCM send failed for token', [
                'user_id' => $user->id,
                'token_id' => $tokenRecord->id,
                'exception' => $exception::class,
            ]);
            return ['accepted' => false, 'retryable' => true, 'failure_code' => 'provider_rejected_temporarily'];
        } catch (\Throwable $exception) {
            self::recordFailure('unexpected');
            Log::error('Unexpected FCM error', [
                'user_id' => $user->id,
                'token_id' => $tokenRecord->id,
                'exception' => $exception::class,
            ]);
            return ['accepted' => false, 'retryable' => true, 'failure_code' => 'provider_error'];
        }
    }

    private static function firstText(string ...$values): string
    {
        foreach ($values as $value) {
            $text = trim($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function circuitIsOpen(): bool
    {
        try {
            return Cache::has(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function recordFailure(string $reason): void
    {
        try {
            Cache::add(self::FAILURE_KEY, 0, now()->addMinute());
            $failures = (int) Cache::increment(self::FAILURE_KEY);
            if ($failures < max(2, (int) config('operations.fcm_circuit_failure_threshold', 3))) {
                return;
            }
            Cache::put(
                self::CIRCUIT_KEY,
                ['reason' => $reason, 'opened_at' => now()->toIso8601String()],
                now()->addSeconds(max(15, (int) config('operations.fcm_circuit_open_seconds', 60)))
            );
        } catch (\Throwable $exception) {
            Log::warning('FCM circuit state could not be recorded.', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private static function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURE_KEY);
            Cache::forget(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            // Push delivery remains successful when monitoring state is unavailable.
        }
    }

    /**
     * Send FCM push notification to multiple users in bulk.
     *
     * @param iterable<User> $users
     * @param string $titleAr
     * @param string $titleEn
     * @param string $messageAr
     * @param string $messageEn
     * @param string|null $link
     * @return void
     */
    public static function sendToUsers(
        iterable $users,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null
    ): void {
        foreach ($users as $user) {
            self::sendToUser($user, $titleAr, $titleEn, $messageAr, $messageEn, $link);
        }
    }
}
