<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookService
{
    private const DEBUG_TOKEN_URL = 'https://graph.facebook.com/debug_token';

    /**
     * Verify Facebook access token and retrieve user data
     *
     * @param string $accessToken
     * @return array ['id', 'name', 'email', 'picture']
     * @throws Exception
     */
    public function verify(string $accessToken): array
    {
        try {
            Log::info('Verifying Facebook token');

            $appId = (string) config('services.facebook.client_id');
            $appSecret = (string) config('services.facebook.client_secret');
            if ($appId === '' || $appSecret === '') {
                throw new Exception('Facebook sign-in is not configured.');
            }

            $timeout = max(3, (int) config('social_auth.timeout_seconds', 10));
            $graphVersion = $this->graphVersion();

            $debugData = Http::acceptJson()
                ->timeout($timeout)
                ->get(self::DEBUG_TOKEN_URL, [
                    'input_token' => $accessToken,
                    'access_token' => $appId . '|' . $appSecret,
                ])
                ->throw()
                ->json('data');

            if (
                !is_array($debugData)
                || empty($debugData['is_valid'])
                || !hash_equals($appId, (string) ($debugData['app_id'] ?? ''))
                || empty($debugData['user_id'])
            ) {
                throw new Exception('Facebook rejected the access token.');
            }

            $response = Http::timeout($timeout)
                ->get('https://graph.facebook.com/' . $graphVersion . '/me', [
                    'fields' => 'id,name,email,picture.height(200).width(200)',
                    'access_token' => $accessToken,
                    'appsecret_proof' => hash_hmac('sha256', $accessToken, $appSecret),
                ])
                ->throw()
                ->json();

            if (
                !isset($response['id'])
                || !hash_equals((string) $debugData['user_id'], (string) $response['id'])
            ) {
                throw new Exception('Invalid Facebook response: missing user ID');
            }

            $picture = null;
            if (isset($response['picture']['data']['url'])) {
                $picture = $response['picture']['data']['url'];
            }

            Log::info('Facebook token verified');

            return [
                'id' => (string)$response['id'],
                'identity_issued_at' => is_numeric($debugData['issued_at'] ?? null)
                    ? (int) $debugData['issued_at']
                    : null,
                'name' => $response['name'] ?? null,
                'email' => $response['email'] ?? null,
                'email_verified' => !empty($response['email']),
                'picture' => $picture,
            ];
        } catch (Exception $e) {
            Log::warning('Facebook identity verification failed');
            throw $e;
        }
    }

    private function graphVersion(): string
    {
        $version = trim((string) config('services.facebook.graph_version', ''));
        if (!preg_match('/^v\d+\.\d+$/', $version) || $version === 'v19.0') {
            throw new Exception('Invalid Facebook Graph API version.');
        }

        return $version;
    }
}
