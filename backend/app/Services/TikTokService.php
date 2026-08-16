<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TikTokService
{
    /**
     * Resolve the identity from TikTok itself. No user id supplied by the app is trusted.
     */
    public function verify(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout((int) config('social_auth.timeout_seconds', 10))
            ->get((string) config('social_auth.tiktok.user_info_url'), [
                'fields' => 'open_id,union_id,display_name,avatar_url',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('TikTok rejected the access token.');
        }

        $payload = $response->json();
        $errorCode = (string) data_get($payload, 'error.code', '');
        $user = data_get($payload, 'data.user');

        if (($errorCode !== '' && $errorCode !== 'ok') || !is_array($user) || empty($user['open_id'])) {
            throw new RuntimeException('TikTok returned an invalid identity response.');
        }

        return [
            'id' => (string) $user['open_id'],
            'name' => isset($user['display_name']) ? (string) $user['display_name'] : null,
            'email' => null,
            'email_verified' => false,
            'picture' => isset($user['avatar_url']) ? (string) $user['avatar_url'] : null,
        ];
    }
}
