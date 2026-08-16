<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AppleService;
use Exception;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class AppleServiceNonceTest extends TestCase
{
    private const KEY_ID = 'apple-test-key';

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.apple.client_id' => 'com.rokn.app']);
        Cache::flush();

        $openSslConfig = tempnam(sys_get_temp_dir(), 'rokn-openssl-');
        self::assertIsString($openSslConfig);
        self::assertNotFalse(file_put_contents(
            $openSslConfig,
            "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n"
        ));

        try {
            $key = openssl_pkey_new([
                'config' => $openSslConfig,
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
            $privateKey = '';
            self::assertTrue(openssl_pkey_export($key, $privateKey, null, ['config' => $openSslConfig]));
            $this->privateKey = $privateKey;

            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertArrayHasKey('rsa', $details);
            $rsa = $details['rsa'];
        } finally {
            unlink($openSslConfig);
        }

        Http::fake([
            'https://appleid.apple.com/auth/keys' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'kid' => self::KEY_ID,
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $this->base64UrlEncode($rsa['n']),
                    'e' => $this->base64UrlEncode($rsa['e']),
                ]],
            ]),
        ]);
    }

    public function test_correct_raw_nonce_is_bound_to_the_signed_hash_claim(): void
    {
        $rawNonce = str_repeat('1', 64);

        $identity = (new AppleService())->verify(
            $this->identityToken(hash('sha256', $rawNonce)),
            $rawNonce
        );

        self::assertSame('apple-user-123', $identity['id']);
        self::assertSame('learner@example.com', $identity['email']);
        self::assertTrue($identity['email_verified']);
    }

    public function test_mismatched_nonce_is_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nonce does not match');

        (new AppleService())->verify(
            $this->identityToken(hash('sha256', str_repeat('2', 64))),
            str_repeat('3', 64)
        );
    }

    public function test_missing_or_malformed_raw_nonce_is_rejected_before_token_use(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Apple sign-in nonce');

        (new AppleService())->verify('unused-token', '');
    }

    public function test_a_successfully_consumed_nonce_cannot_be_replayed(): void
    {
        $rawNonce = str_repeat('4', 64);
        $token = $this->identityToken(hash('sha256', $rawNonce));
        $service = new AppleService();

        $service->verify($token, $rawNonce);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('nonce was already used');
        $service->verify($token, $rawNonce);
    }

    private function identityToken(string $nonce): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'sub' => 'apple-user-123',
            'aud' => 'com.rokn.app',
            'iat' => $now - 1,
            'exp' => $now + 300,
            'nonce' => $nonce,
            'email' => 'Learner@Example.com',
            'email_verified' => true,
        ], $this->privateKey, 'RS256', self::KEY_ID);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
