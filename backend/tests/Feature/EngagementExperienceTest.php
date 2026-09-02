<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\User;
use App\Services\StudentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EngagementExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendUserPushNotification::class, SendWhatsAppMessage::class]);
        config()->set('whatsapp.linking.bot_phone', '201001234567');
        config()->set('whatsapp.linking.webhook_secret', 'engagement-test-webhook-secret');
        config()->set('whatsapp.whatspie.api_key', '');
    }

    public function test_guest_prompt_is_dashboard_driven_and_resolves_the_real_welcome_amount(): void
    {
        RewardRule::query()->where('event_key', 'welcome_bonus')->update(['coins_amount' => 27]);

        $this->getJson('/api/v1/engagement/messages/guest_registration_prompt')
            ->assertOk()
            ->assertJsonPath('data.key', 'guest_registration_prompt')
            ->assertJsonPath('data.coins', 27)
            ->assertJsonPath('data.dismissible', true)
            ->assertJsonFragment(['action_label_ar' => 'تسجيل الدخول']);
    }

    public function test_inbound_whatsapp_message_verifies_the_number_and_credits_only_once(): void
    {
        $user = $this->student('whatsapp-student@rokn.test');
        $token = $user->generateApiToken();
        $method = CoinEarningMethod::query()->where('action_key', 'link_whatsapp')->firstOrFail();

        $start = $this->withToken($token)
            ->postJson('/api/v1/coin-earning-methods/' . $method->id . '/start')
            ->assertOk()
            ->assertJsonPath('data.task_state', 'started');
        $actionUrl = (string) $start->json('data.action_url');
        self::assertStringStartsWith('https://wa.me/201001234567?text=', $actionUrl);
        parse_str((string) parse_url($actionUrl, PHP_URL_QUERY), $query);
        $message = (string) ($query['text'] ?? '');
        self::assertStringContainsString('ROKN_LINK_', $message);
        $this->assertDatabaseMissing('user_whatsapp_connections', ['user_id' => $user->id]);

        // Whatspie v1 posts the message body at the root and lets the webhook
        // URL carry the shared token. Exercise that production contract here;
        // the header and nested payload variants remain backwards compatible.
        $first = $this->postJson('/api/v1/whatsapp/webhook?token=engagement-test-webhook-secret', [
                'from' => '201012345678',
                'message' => $message,
                'message_id' => 'whatspie-message-1',
            ])->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('already_claimed', false);
        self::assertSame((int) $method->coins_amount, (int) $first->json('coins_added'));
        Queue::assertPushed(SendWhatsAppMessage::class);

        $this->postJson('/api/v1/whatsapp/webhook?token=engagement-test-webhook-secret', [
                'from' => '201012345678',
                'message' => $message,
                'message_id' => 'whatspie-message-1',
            ])->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('already_claimed', true)
            ->assertJsonPath('coins_added', 0);

        $this->assertDatabaseCount('user_coin_earnings', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('user_whatsapp_connections', [
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'ownership_verified' => true,
            'marketing_opt_in' => false,
        ]);
    }

    public function test_whatsapp_webhook_rejects_requests_without_the_shared_secret(): void
    {
        $this->postJson('/api/v1/whatsapp/webhook', [
            'sender' => '201012345678',
            'message' => ['text' => 'ROKN_LINK_' . str_repeat('a', 48)],
        ])->assertUnauthorized();
    }

    public function test_recommended_provider_bonus_is_added_to_the_same_one_time_welcome_credit(): void
    {
        $settings = Setting::query()->firstOrCreate([]);
        $settings->update([
            'recommended_social_provider' => 'google',
            'recommended_provider_bonus_coins' => 9,
        ]);
        RewardRule::query()->where('event_key', 'welcome_bonus')->update(['coins_amount' => 20]);
        $user = $this->student('google-student@rokn.test', 'google');
        $user->forceFill([
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
            'wallet_purchased_coins' => 0,
        ])->save();

        self::assertSame(29, StudentNotificationService::sendRegistrationBonus($user));
        self::assertSame(29, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, StudentNotificationService::sendRegistrationBonus($user));
    }

    public function test_next_reward_message_returns_only_one_eligible_unclaimed_task(): void
    {
        $user = $this->student('one-offer@rokn.test');
        $token = $user->generateApiToken();

        $response = $this->withToken($token)->getJson('/api/v1/engagement/next')
            ->assertOk()
            ->assertJsonPath('data.key', 'coin_offer');

        self::assertNotEmpty($response->json('data.task_id'));
        self::assertStringStartsWith('coin-offer:', (string) $response->json('data.campaign_key'));
        self::assertIsArray($response->json('data'));
    }

    public function test_reward_tasks_never_expose_the_external_visit_claim_mechanism(): void
    {
        $user = $this->student('wallet-copy@rokn.test');
        $token = $user->generateApiToken();
        CoinEarningMethod::query()->create([
            'title_ar' => 'افتح الصفحة ثم عد للمطالبة',
            'title_en' => 'Open then return to claim',
            'coins_amount' => 75,
            'action_key' => 'demo_instagram',
            'action_url' => 'https://instagram.com/rokn.app',
            'requires_external_visit' => true,
            'verification_delay_seconds' => 3,
            'is_active' => true,
            'is_repeatable' => false,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/coin-earning-methods')
            ->assertOk();
        $task = collect($response->json('data'))
            ->firstWhere('action_key', 'demo_instagram');

        self::assertIsArray($task);
        self::assertSame('تابع ركن على Instagram', $task['title_ar']);
        self::assertStringNotContainsString('عد', $task['title_ar']);
        self::assertStringNotContainsString('مطالبة', $task['title_ar']);
    }

    private function student(string $email, string $provider = 'google'): User
    {
        return User::query()->forceCreate([
            'name' => 'Engagement Student',
            'name_ar' => 'طالب التفاعل',
            'name_en' => 'Engagement Student',
            'email' => $email,
            'role' => 'client',
            'active' => true,
            'social_provider' => $provider,
            'social_id' => $provider . ':' . $email,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
    }
}
