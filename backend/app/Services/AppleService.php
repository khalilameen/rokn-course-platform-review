<?php

namespace App\Services;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AppleService
{
    private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';
    private const CACHE_KEY = 'oauth:apple:jwks:v1';
    private const NONCE_CACHE_PREFIX = 'oauth:apple:nonce:v1:';

    public function verify(string $identityToken, string $rawNonce): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $rawNonce) !== 1) {
            throw new Exception('Invalid Apple sign-in nonce.');
        }

        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw new Exception('Invalid Apple identity token format.');
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        $kid = trim((string) ($header['kid'] ?? ''));
        if ($kid === '' || ($header['alg'] ?? null) !== 'RS256') {
            throw new Exception('Invalid Apple identity token header.');
        }

        $decoded = JWT::decode($identityToken, $this->keyFor($kid));
        $issuedAt = $decoded->iat ?? null;
        $expiresAt = $decoded->exp ?? null;
        if (
            ($decoded->iss ?? null) !== self::ISSUER
            || empty($decoded->sub)
            || !is_int($issuedAt)
            || !is_int($expiresAt)
            || $issuedAt <= 0
            || $expiresAt <= time()
            || $issuedAt >= $expiresAt
        ) {
            throw new Exception('Invalid Apple identity token claims.');
        }

        $expectedAudiences = array_values(array_filter(array_map(
            'trim', explode(',', (string) config('services.apple.client_id'))
        )));
        if ($expectedAudiences === []) {
            throw new Exception('Apple client ID is not configured.');
        }
        $tokenAudiences = is_array($decoded->aud ?? null)
            ? $decoded->aud
            : [(string) ($decoded->aud ?? '')];
        if (array_intersect($expectedAudiences, $tokenAudiences) === []) {
            throw new Exception('Apple identity token audience does not match.');
        }

        $claim = $decoded->nonce ?? null;
        $expectedNonce = hash('sha256', $rawNonce);
        if (
            !is_string($claim)
            || preg_match('/\A[a-f0-9]{64}\z/', $claim) !== 1
            || !hash_equals($expectedNonce, $claim)
        ) {
            throw new Exception('Apple identity token nonce does not match.');
        }

        // Bind the nonce to this exact signed credential across all API
        // instances. The mobile client retains the token until its session is
        // durable, so an identical retry after a database/network failure must
        // remain valid. A different token can never reuse the nonce.
        $nonceKey = self::NONCE_CACHE_PREFIX . $expectedNonce;
        $credentialHash = hash('sha256', $identityToken);
        $claimedCredentialHash = Cache::get($nonceKey);
        if ($claimedCredentialHash === null) {
            if (Cache::add(
                $nonceKey,
                $credentialHash,
                now()->addSeconds($expiresAt - time())
            )) {
                $claimedCredentialHash = $credentialHash;
            } else {
                $claimedCredentialHash = Cache::get($nonceKey);
            }
        }
        if (
            !is_string($claimedCredentialHash)
            || !hash_equals($claimedCredentialHash, $credentialHash)
        ) {
            throw new Exception('Apple identity token nonce was already used.');
        }

        $email = isset($decoded->email) && filter_var($decoded->email, FILTER_VALIDATE_EMAIL)
            ? strtolower((string) $decoded->email)
            : null;
        $verifiedClaim = $decoded->email_verified ?? false;

        return [
            'id' => (string) $decoded->sub,
            'identity_issued_at' => $issuedAt,
            'name' => null,
            'email' => $email,
            'email_verified' => $email !== null && in_array($verifiedClaim, [true, 1, 'true', '1'], true),
            'is_private_email' => filter_var($decoded->is_private_email ?? false, FILTER_VALIDATE_BOOLEAN),
            'picture' => null,
        ];
    }

    private function keyFor(string $kid): \Firebase\JWT\Key
    {
        $keys = JWK::parseKeySet($this->appleJwks(false), 'RS256');
        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        // Apple can rotate signing keys before the normal cache TTL.
        $keys = JWK::parseKeySet($this->appleJwks(true), 'RS256');
        if (!isset($keys[$kid])) {
            throw new Exception('No matching Apple signing key was found.');
        }

        return $keys[$kid];
    }

    private function appleJwks(bool $forceRefresh): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function (): array {
            $payload = Http::acceptJson()
                ->timeout(8)
                ->retry(2, 150)
                ->get(self::APPLE_KEYS_URL)
                ->throw()
                ->json();
            if (!is_array($payload) || !isset($payload['keys']) || !is_array($payload['keys'])) {
                throw new Exception('Invalid Apple public-keys response.');
            }

            return $payload;
        });
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new Exception('Invalid base64url value.');
        }

        return $decoded;
    }
}
