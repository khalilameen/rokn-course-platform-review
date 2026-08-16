<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GoogleService;
use Exception;
use Mockery\MockInterface;
use Tests\TestCase;

final class AppleNonceAuthenticationTest extends TestCase
{
    public function test_apple_social_login_requires_the_raw_nonce(): void
    {
        $this->postJson('/api/v1/social-login', [
            'provider' => 'apple',
            'token' => 'signed-identity-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['nonce']);
    }

    public function test_nonce_remains_optional_for_google_social_login(): void
    {
        $this->mock(GoogleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('google-id-token')
                ->andThrow(new Exception('expected test rejection'));
        });

        $this->postJson('/api/v1/social-login', [
            'provider' => 'google',
            'token' => 'google-id-token',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'social_identity_verification_failed')
            ->assertJsonMissingValidationErrors(['nonce']);
    }
}
