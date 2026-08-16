<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;

class FcmNotificationService
{
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
        )['delivered'];
    }

    /** @return array{delivered:bool,retryable:bool} */
    public static function sendToUserDetailed(
        User $user,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link = null,
        array $extraData = []
    ): array {
        // A user can still see their in-app inbox after opting out, but we must
        // never wake their device with a push notification once they turn it off.
        if (!(bool) $user->notifications_status) {
            return ['delivered' => false, 'retryable' => false];
        }

        $tokens = $user->relationLoaded('deviceTokens')
            ? $user->deviceTokens
            : UserDeviceToken::where('user_id', $user->id)->get();

        if ($tokens->isEmpty()) {
            return ['delivered' => false, 'retryable' => false];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (\Throwable $e) {
            Log::warning('FCM messaging service unavailable', [
                'exception' => $e::class,
            ]);
            return ['delivered' => false, 'retryable' => true];
        }

        $attempted = false;
        $retryableFailure = false;
        $staleTokenIds = [];

        foreach ($tokens as $tokenRecord) {
            $deviceToken = $tokenRecord->device_token;
            if (empty($deviceToken)) {
                continue;
            }

            $isEnglish = str_starts_with(
                strtolower((string) ($user->preferred_locale ?: 'ar')),
                'en'
            );
            $title = $isEnglish ? $titleEn : $titleAr;
            $body  = $isEnglish ? $messageEn : $messageAr;

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
            } catch (NotFound $e) {
                $staleTokenIds[] = $tokenRecord->id;
            } catch (MessagingException $e) {
                $retryableFailure = true;
                Log::warning('FCM send failed for token', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable $e) {
                $retryableFailure = true;
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
            'delivered' => $attempted,
            'retryable' => !$attempted && $retryableFailure,
        ];
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
