<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication contract tests for Rokn's social-only mobile sign-in.
 *
 * Phone/password and OTP routes deliberately remain as 410 endpoints so an
 * old mobile build gets a deterministic upgrade response instead of silently
 * reintroducing a product flow that Rokn no longer supports.
 */
class AuthEndpointTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        RateLimiter::clear('auth:127.0.0.1');

        parent::tearDown();
    }

    public function test_auth_methods_advertise_social_only_sign_in(): void
    {
        config()->set([
            'services.google.client_id' => 'configured',
            'services.google.client_secret' => 'configured',
            'services.facebook.client_id' => null,
            'services.facebook.client_secret' => null,
            'services.tiktok.client_key' => null,
            'services.tiktok.client_secret' => null,
            'services.apple.client_id' => null,
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.otp_enabled', false)
            ->assertJsonPath('data.password_login_visible', false)
            ->assertJsonPath('data.welcome_bonus_coins', 20)
            ->assertJsonPath('data.providers', ['google'])
            ->assertJsonPath('data.recommended_provider', 'google')
            ->assertJsonStructure([
                'data' => ['providers', 'authorization_urls', 'recommendation_badge'],
            ]);
    }

    public function test_auth_methods_hide_facebook_until_its_graph_contract_is_safe(): void
    {
        config()->set([
            'services.google.client_id' => 'configured',
            'services.google.client_secret' => 'configured',
            'services.facebook.client_id' => 'configured',
            'services.facebook.client_secret' => 'configured',
            'services.facebook.graph_version' => 'v19.0',
            'services.tiktok.client_key' => null,
            'services.tiktok.client_secret' => null,
            'services.apple.client_id' => null,
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['google']);

        config()->set('services.facebook.graph_version', 'v999.0');

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['facebook', 'google']);
    }

    public function test_legacy_phone_password_and_otp_routes_are_retired_consistently(): void
    {
        foreach ([
            '/api/v1/login',
            '/api/v1/register',
            '/api/v1/send-verification',
            '/api/v1/verify-phone',
            '/api/v1/forgot-password',
            '/api/v1/reset-password',
        ] as $endpoint) {
            $this->postJson($endpoint, [])->assertStatus(410)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'otp_not_supported');
        }
    }

    public function test_expired_social_completion_code_is_rejected_without_creating_a_user(): void
    {
        $before = \App\Models\User::query()->count();

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => str_repeat('x', 64),
            'device_os' => 'android',
        ])->assertStatus(410)
            ->assertJsonPath('code', 'social_login_expired');

        $this->assertSame($before, \App\Models\User::query()->count());
    }

    public function test_social_auth_completion_is_bounded_without_throttling_catalog_reads(): void
    {
        RateLimiter::clear('auth:127.0.0.1');

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->postJson('/api/v1/social-auth/complete', [
                'code' => str_repeat('x', 64),
                'device_os' => 'android',
            ])->assertStatus(410);
        }

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => str_repeat('x', 64),
            'device_os' => 'android',
        ])->assertStatus(429);

        // Public discovery remains usable after the stricter auth bucket fills.
        $this->getJson('/api/v1/auth-methods')->assertOk();
    }

    public function test_public_notification_resource_does_not_advertise_an_unimplemented_show_route(): void
    {
        $showRoute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/admin_notification/{admin_notification}');

        $this->assertNull($showRoute);
    }

    public function test_unknown_social_provider_is_not_routable(): void
    {
        $this->get('/api/v1/social-auth/unknown/start?return_to=rokn%3A%2F%2Fauth')
            ->assertNotFound();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_protected_api_routes_never_redirect_html_clients_to_web_login(): void
    {
        foreach (['/api/v1/user/profile', '/api/user/profile'] as $endpoint) {
            $this->withHeaders(['Accept' => 'text/html'])
                ->get($endpoint)
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeaderMissing('Location')
                ->assertJson([
                    'status' => 401,
                    'success' => false,
                    'data' => null,
                    'message' => 'Unauthenticated',
                    'code' => 'unauthenticated',
                ]);
        }
    }

    public function test_logout_revokes_only_the_current_api_session(): void
    {
        $firstToken = $this->user->generateApiToken();
        $secondToken = $this->user->generateApiToken();

        $this->withToken($secondToken)->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', $firstToken)]);
        $this->assertDatabaseMissing('api_tokens', ['token' => hash('sha256', $secondToken)]);

        // Laravel's feature runner reuses the application between the two
        // synthetic requests. Re-resolve this request-bound guard just as a
        // real HTTP request lifecycle does.
        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/v1/user/profile')
            ->assertOk();
    }

    public function test_user_sessions_keep_the_standard_api_envelope(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->uuid('session_id')->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('app_build', 16)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        $plainToken = $this->user->generateApiToken();

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/user/sessions')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Sessions retrieved successfully')
            ->assertJsonStructure(['data' => [['id', 'platform', 'current']]]);

        $sessionId = (string) $response->json('data.0.id');
        self::assertNotSame('', $sessionId);

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->deleteJson('/api/v1/user/sessions/' . $sessionId)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['signed_out'], 'signed_out']);
    }

    public function test_query_body_and_basic_token_transports_are_rejected_by_default(): void
    {
        $plainToken = $this->user->generateApiToken();

        $this->getJson('/api/v1/user/profile?api_token=' . urlencode($plainToken))
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/update_profile', [
            'api_token' => $plainToken,
            'name' => 'Transport Probe',
        ])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode('mobile:' . $plainToken),
        ])->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_bearer_token_transport_remains_authoritative(): void
    {
        $plainToken = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk();
    }

    public function test_bearer_token_takes_precedence_over_legacy_query_transport(): void
    {
        config(['multiple-tokens-auth.allow_legacy_transports' => true]);
        $plainToken = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile?api_token=attacker-controlled-value')
            ->assertOk();
    }

    public function test_inactive_account_token_is_rejected_deleted_and_never_renewed(): void
    {
        $plainToken = $this->user->generateApiToken();
        $storedToken = hash('sha256', $plainToken);
        \App\Models\ApiToken::query()
            ->where('token', $storedToken)
            ->update(['expired_at' => now()->addDays(2)]);

        $this->user->forceFill(['active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertUnauthorized();

        $this->assertDatabaseMissing('api_tokens', ['token' => $storedToken]);
    }

    public function test_soft_deleted_account_token_is_rejected_and_deleted(): void
    {
        $plainToken = $this->user->generateApiToken();
        $storedToken = hash('sha256', $plainToken);
        $this->user->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertUnauthorized();

        $this->assertDatabaseMissing('api_tokens', ['token' => $storedToken]);
    }

    public function test_logout_does_not_delete_other_installations_push_tokens(): void
    {
        $apiToken = $this->user->generateApiToken();
        foreach (['phone-a-fcm-token', 'phone-b-fcm-token'] as $deviceToken) {
            \App\Models\UserDeviceToken::query()->create([
                'user_id' => $this->user->id,
                'device_token' => $deviceToken,
                'device_type' => 'android',
                'device_os' => 'android',
            ]);
        }

        $this->withToken($apiToken)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-a-fcm-token',
        ]);
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-b-fcm-token',
        ]);
    }

    public function test_logout_atomically_removes_only_this_installations_push_token(): void
    {
        $apiToken = $this->user->generateApiToken();
        foreach (['phone-a-fcm-token', 'phone-b-fcm-token'] as $deviceToken) {
            \App\Models\UserDeviceToken::query()->create([
                'user_id' => $this->user->id,
                'device_token' => $deviceToken,
                'device_type' => 'android',
                'device_os' => 'android',
            ]);
        }

        $this->withToken($apiToken)->postJson('/api/v1/logout', [
            'device_token' => 'phone-a-fcm-token',
        ])->assertOk();

        $this->assertDatabaseMissing('api_tokens', ['token' => $apiToken]);
        $this->assertDatabaseMissing('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-a-fcm-token',
        ]);
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-b-fcm-token',
        ]);
    }

    public function test_logout_is_not_exposed_as_a_state_changing_get_request(): void
    {
        $getLogoutRoute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/logout'
                && in_array('GET', $route->methods(), true));

        $this->assertNull($getLogoutRoute);
    }

    public function test_authenticated_user_can_register_and_remove_this_device_push_token(): void
    {
        $token = 'fcm-test-token-for-one-installation';

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/device-token', [
            'device_token' => $token,
            'device_type' => 'android',
            'device_os' => 'android',
        ])->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => $token,
        ]);

        $this->actingAs($this->user, 'api')->deleteJson('/api/v1/user/device-token', [
            'device_token' => $token,
        ])->assertOk();

        $this->assertDatabaseMissing('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => $token,
        ]);
    }

    public function test_authenticated_user_can_delete_own_account(): void
    {
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => 'facebook-owner-1',
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-owner-1',
            'last_verified_at' => now(),
        ]);
        $reauthToken = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($reauthToken)->postJson('/api/v1/delete-account')
            ->assertOk();

        $deleted = \App\Models\User::withTrashed()->findOrFail($this->user->id);
        $this->assertFalse((bool) $deleted->active);
        $this->assertNotNull($deleted->deleted_at);
    }

    public function test_account_deletion_rejects_an_ordinary_or_wrong_provider_session(): void
    {
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => 'facebook-owner-2',
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-owner-2',
            'last_verified_at' => now(),
        ]);
        $token = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/delete-account')
            ->assertForbidden()
            ->assertJsonPath('code', 'social_reauthentication_required');

        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-owner-2',
            'last_verified_at' => now()->subMinutes(11),
        ]);
        \App\Models\ApiToken::query()
            ->where('token', hash('sha256', $token))
            ->update(['issued_at' => now()->subMinutes(11)]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/delete-account')
            ->assertForbidden()
            ->assertJsonPath('code', 'social_reauthentication_required');

        $this->assertTrue((bool) $this->user->fresh()->active);
    }

    public function test_deleted_identity_cannot_repeat_welcome_or_one_time_task_rewards(): void
    {
        config(['social_auth.reward_tombstone_hmac_key' => 'unit-test-tombstone-key']);
        $providerId = 'raw-provider-id-must-not-be-stored';
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => $providerId,
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => $providerId,
            'last_verified_at' => now(),
        ]);

        $registerId = (int) \App\Models\CoinEarningMethod::query()
            ->where('action_key', 'register')
            ->value('id');
        $instagramId = (int) \Illuminate\Support\Facades\DB::table('coin_earning_methods')->insertGetId([
            'action_key' => 'instagram',
            'coins_amount' => 75,
            'is_repeatable' => false,
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $anonymousMethodId = (int) \Illuminate\Support\Facades\DB::table('coin_earning_methods')->insertGetId([
            'action_key' => null,
            'coins_amount' => 40,
            'is_repeatable' => false,
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$registerId, $instagramId, $anonymousMethodId] as $methodId) {
            $this->user->coinEarnings()->create([
                'coin_earning_method_id' => $methodId,
                'amount' => 20,
            ]);
        }

        $reauthToken = $this->user->generateApiToken();
        $this->app['auth']->forgetGuards();
        $this->withToken($reauthToken)->postJson('/api/v1/delete-account')->assertOk();

        $tombstone = \App\Models\DeletedSocialRewardTombstone::query()->sole();
        $this->assertSame([
            'method:' . $anonymousMethodId,
            'task:instagram',
            'welcome_bonus',
        ], $tombstone->consumed_reward_keys);
        $this->assertStringNotContainsString($providerId, (string) $tombstone->identity_hmac);
        $this->assertDatabaseMissing('deleted_social_reward_tombstones', [
            'identity_hmac' => $providerId,
        ]);

        $replacement = \App\Models\User::query()->forceCreate([
            'name' => 'Same learner, new account row',
            'email' => 'replacement@rokn.test',
            'password' => bcrypt('irrelevant'),
            'role' => 'client',
            'active' => true,
            'social_provider' => 'facebook',
            'social_id' => $providerId,
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
        \App\Models\SocialAccount::query()->create([
            'user_id' => $replacement->id,
            'provider' => 'facebook',
            'provider_user_id' => $providerId,
            'last_verified_at' => now(),
        ]);

        $this->assertSame(0, \App\Services\StudentNotificationService::sendRegistrationBonus($replacement));
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $replacement->id,
            'category' => 'welcome_bonus',
        ]);

        $replacementToken = $replacement->generateApiToken();
        $this->app['auth']->forgetGuards();
        $this->withToken($replacementToken)
            ->postJson('/api/v1/claim-coins', ['method_id' => $instagramId])
            ->assertOk()
            ->assertJsonPath('data.already_claimed', true)
            ->assertJsonPath('data.earned_amount', 0);
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $replacement->id,
            'category' => 'task_reward',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($replacementToken)
            ->postJson('/api/v1/claim-coins', ['method_id' => $anonymousMethodId])
            ->assertOk()
            ->assertJsonPath('data.already_claimed', true)
            ->assertJsonPath('data.earned_amount', 0);
    }
}
