<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SocialAuthConfigurationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_misconfigured_external_return_url_is_never_accepted_at_runtime(): void
    {
        config([
            'social_auth.allow_legacy_pkce' => false,
            'social_auth.return_urls' => [
                'rokn://auth',
                'https://attacker.invalid/callback',
            ],
        ]);
        $challenge = str_repeat('A', 43);

        $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'https://attacker.invalid/callback',
        ]))->assertStatus(422);
    }

    public function test_explicit_rokn_callback_remains_usable_with_pkce(): void
    {
        config([
            'social_auth.allow_legacy_pkce' => false,
            'social_auth.return_urls' => ['rokn://auth'],
            'services.google.client_id' => 'test-client-id',
        ]);
        $challenge = str_repeat('B', 43);

        $response = $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'rokn://auth',
        ]));

        $response->assertRedirect();
        self::assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth?',
            (string) $response->headers->get('Location')
        );
    }
}
