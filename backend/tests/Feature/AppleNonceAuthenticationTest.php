<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SocialProviderUnavailableException;
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
        config([
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'test-client-id',
        ]);
        $this->mock(GoogleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                // SignController always forwards the optional expected nonce
                // hash. A direct Google login has no OAuth attempt, so the
                // second argument is explicitly null rather than omitted.
                ->with('google-id-token', null)
                ->andThrow(new Exception('expected test rejection'));
        });

        $this->postJson('/api/v1/social-login', [
            'provider' => 'google',
            'token' => 'google-id-token',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'social_identity_verification_failed')
            ->assertJsonMissingValidationErrors(['nonce']);
    }

    public function test_provider_outage_is_retryable_and_not_reported_as_a_bad_account(): void
    {
        config([
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'test-client-id',
        ]);
        $this->mock(GoogleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->andThrow(new SocialProviderUnavailableException('provider timeout'));
        });

        $this->postJson('/api/v1/social-login', [
            'provider' => 'google',
            'token' => 'google-id-token',
        ])->assertServiceUnavailable()
            ->assertJsonPath('code', 'social_provider_unavailable');
    }
}
