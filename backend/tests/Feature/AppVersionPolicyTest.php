<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppVersionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Schema::create('app_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('distribution_channel')->nullable();
            $table->string('version_name');
            $table->integer('version_code')->nullable();
            $table->integer('build_number')->nullable();
            $table->boolean('is_force_update')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('update_message_ar')->nullable();
            $table->text('update_message_en')->nullable();
            $table->string('download_url')->nullable();
            $table->text('release_notes_ar')->nullable();
            $table->text('release_notes_en')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('app_versions');
        parent::tearDown();
    }

    public function test_no_active_version_is_a_valid_no_update_response(): void
    {
        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 10,
        ])->assertOk()
            ->assertJsonPath('data.update_required', false)
            ->assertJsonPath('data.is_force_update', false);
    }

    public function test_android_uses_numeric_version_code_and_detects_any_forced_hop(): void
    {
        $this->insertVersion('android', '1.1.0', 11, null, true, 'play');
        $this->insertVersion('android', '1.2.0', 12, null, false, 'play');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 10,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', true)
            ->assertJsonPath('data.latest_version_code', 12)
            ->assertJsonPath('data.latest_version', '1.2.0');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 12,
            'distribution_channel' => 'play',
        ])->assertOk()->assertJsonPath('data.update_required', false);
    }

    public function test_ios_build_number_is_authoritative_even_when_marketing_versions_disagree(): void
    {
        $this->insertVersion('ios', '9.0.0', null, 39, false, 'appstore');
        $this->insertVersion('ios', '1.9.0', null, 40, false, 'appstore');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => '9.0.0',
            'build_number' => 39,
            'distribution_channel' => 'appstore',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.latest_build_number', 40)
            ->assertJsonPath('data.latest_version', '1.9.0');
    }

    public function test_ios_old_clients_fall_back_to_semantic_marketing_version(): void
    {
        $this->insertVersion('ios', '1.2.0', null, 20, true);

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => '1.1.0',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', true);
    }

    public function test_invalid_platform_specific_versions_are_rejected(): void
    {
        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => '1.2.3',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => 'latest',
            'build_number' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['version', 'build_number']);
    }

    public function test_android_channels_never_leak_release_urls_into_each_other(): void
    {
        $this->insertVersion('android', '4.0.0', 40, null, false, 'play');
        $this->insertVersion('android', '5.0.0', 50, null, true, 'direct');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 39,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 40)
            ->assertJsonPath('data.distribution_channel', 'play')
            ->assertJsonPath(
                'data.download_url',
                'https://play.google.com/store/apps/details?id=com.rokn',
            );

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 39,
            'distribution_channel' => 'direct',
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 50)
            ->assertJsonPath('data.distribution_channel', 'direct')
            ->assertJsonPath('data.download_url', 'https://rokn.app/downloads/Rokn.apk');
    }

    private function insertVersion(
        string $platform,
        string $name,
        ?int $code,
        ?int $build,
        bool $force,
        ?string $channel = null,
    ): void {
        DB::table('app_versions')->insert([
            'platform' => $platform,
            'distribution_channel' => $channel,
            'version_name' => $name,
            'version_code' => $code,
            'build_number' => $build,
            'is_force_update' => $force,
            'is_active' => true,
            'download_url' => match ($channel) {
                'appstore' => 'https://apps.apple.com/app/id123456789',
                'direct' => 'https://rokn.app/downloads/Rokn.apk',
                default => 'https://play.google.com/store/apps/details?id=com.rokn',
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
