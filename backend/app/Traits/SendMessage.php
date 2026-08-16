<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

final class SendMessage
{
    use Queueable;

    /** @param array<string, mixed> $info */
    public static function sendAndroid(mixed $androidUsers, array $info): void
    {
        self::sendPush($androidUsers, $info);
    }

    /** @param array<string, mixed> $info */
    public static function sendIos(mixed $iosUsers, array $info): void
    {
        self::sendPush($iosUsers, $info);
    }

    /** @param array<string, mixed> $info */
    protected static function sendPush(mixed $users, array $info): void
    {
        if (empty($users)) {
            return;
        }

        if (! is_iterable($users)) {
            $users = [$users];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (Throwable $exception) {
            Log::warning('FCM messaging service unavailable in SendMessage', [
                'exception' => $exception::class,
            ]);

            return;
        }

        $title = (string) ($info['title'] ?? '');
        $body = (string) ($info['message'] ?? '');
        $data = [
            'message' => $body,
            'latitude' => (string) ($info['latitude'] ?? ''),
            'longitude' => (string) ($info['longitude'] ?? ''),
            'image_link' => (string) ($info['image'] ?? ''),
            'vibrate' => '1',
            'sound' => '1',
            'badge' => '1',
        ];

        if (isset($info['user'])) {
            $encodedUser = is_string($info['user'])
                ? $info['user']
                : json_encode($info['user']);
            if (is_string($encodedUser)) {
                $data['user'] = $encodedUser;
            }
        }

        foreach ($users as $deviceToken) {
            $deviceToken = trim((string) $deviceToken);
            if ($deviceToken === '') {
                continue;
            }

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            try {
                $messaging->send($message);
            } catch (Throwable $exception) {
                Log::warning('FCM send failed in SendMessage', [
                    'token_fingerprint' => substr(hash('sha256', $deviceToken), 0, 12),
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
