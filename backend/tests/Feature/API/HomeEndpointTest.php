<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering Main Page API endpoints:
 * mobile-main-page, main, settings, and app version checking.
 */
class HomeEndpointTest extends ApiTestCase
{
    public function test_can_get_mobile_main_page(): void
    {
        $response = $this->getJson('/api/v1/mobile-main-page');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_get_web_main_page(): void
    {
        $response = $this->getJson('/api/v1/main');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_get_app_settings(): void
    {
        Schema::create('design_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('whatsapp_url')->nullable();
            $table->string('telegram_url')->nullable();
            $table->string('technical_contact')->nullable();
            $table->text('policy_content_ar')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $response = $this->getJson('/api/v1/settings');
            $response->assertOk()
                ->assertJsonPath('data.0.privacy_url', url('/privacy-policy'))
                ->assertJsonPath('data.0.terms_url', url('/terms'))
                ->assertJsonPath('data.0.returns_policy_url', url('/returns-policy'))
                ->assertJsonPath('data.0.account_deletion_url', url('/account-deletion'));
        } finally {
            Schema::dropIfExists('design_settings');
        }
    }

    public function test_can_check_app_version(): void
    {
        $response = $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => '1.0.0'
        ]);
        $this->assertNotEquals(404, $response->status());
    }
}
